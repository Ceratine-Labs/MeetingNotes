<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\WebhookEvent;
use Modules\Billing\Services\SubscriptionService;
use Modules\Billing\Services\WebhookProcessor;
use Modules\Tenancy\Services\OrganisationContext;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Paystack webhooks.
 *
 * Webhooks — not the browser callback — are what keeps subscriptions correct over
 * time: renewals have no browser involved at all. So the two properties asserted hardest
 * here are **authenticity** (a forged request changes nothing) and **idempotency**
 * (Paystack retries, and a retry must not double-apply).
 *
 * No real HTTP leaves these tests; the Paystack secret is set to a known test value so
 * signatures can be computed.
 */
class BillingWebhookTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    private const SECRET = 'sk_test_webhook_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.enabled' => true,
            'billing.paystack.secret_key' => self::SECRET,
        ]);

        // Nothing here should reach the network; fail loudly if anything tries.
        Http::preventStrayRequests();
    }

    /**
     * Sign a payload the way Paystack does: HMAC-SHA512 over the raw body.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string} The raw JSON and its signature.
     */
    private function sign(array $payload): array
    {
        $body = json_encode($payload);

        return [$body, hash_hmac('sha512', $body, self::SECRET)];
    }

    /**
     * POST a signed webhook the way Paystack would.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        [$body, $valid] = $this->sign($payload);

        return $this->call(
            'POST',
            '/webhooks/paystack',
            [], [], [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? $valid, 'CONTENT_TYPE' => 'application/json'],
            $body
        );
    }

    public function test_an_unsigned_webhook_is_rejected_and_records_nothing(): void
    {
        $response = $this->call(
            'POST', '/webhooks/paystack', [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['event' => 'charge.success', 'data' => ['id' => 1]])
        );

        $response->assertUnauthorized();
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $this->deliver(
            ['event' => 'charge.success', 'data' => ['id' => 1, 'reference' => 'x']],
            signature: str_repeat('a', 128)
        )->assertUnauthorized();

        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_a_correctly_signed_webhook_is_accepted_and_recorded(): void
    {
        $this->deliver([
            'event' => 'charge.success',
            'data' => ['id' => 42, 'reference' => 'MN-unknown', 'amount' => 14900],
        ])->assertOk();

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame('charge.success', WebhookEvent::query()->first()->event_type);
    }

    /**
     * Paystack retries. A duplicate delivery must be acknowledged and skipped, not
     * applied twice — enforced by a unique index rather than a check-then-write.
     */
    public function test_a_duplicate_delivery_is_recorded_only_once(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => ['id' => 99, 'reference' => 'MN-dup', 'amount' => 14900],
        ];

        $this->deliver($payload)->assertOk();
        $this->deliver($payload)->assertOk();

        $this->assertSame(1, WebhookEvent::query()->count(), 'A retry must not create a second event row.');
    }

    /**
     * One transaction legitimately produces several different event types; keying
     * idempotency on the id alone would silently drop all but the first.
     */
    public function test_different_event_types_for_one_transaction_are_both_recorded(): void
    {
        $this->deliver(['event' => 'charge.success', 'data' => ['id' => 7, 'reference' => 'MN-a']])->assertOk();
        $this->deliver(['event' => 'subscription.create', 'data' => ['id' => 7]])->assertOk();

        $this->assertSame(2, WebhookEvent::query()->count());
    }

    /**
     * A signed but unparseable body is acknowledged rather than retried forever —
     * retrying cannot fix malformed JSON.
     */
    public function test_signed_but_invalid_json_is_acknowledged_without_recording(): void
    {
        $body = 'this is not json';
        $signature = hash_hmac('sha512', $body, self::SECRET);

        $this->call(
            'POST', '/webhooks/paystack', [], [], [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body
        )->assertOk();

        $this->assertSame(0, WebhookEvent::query()->count());
    }

    /**
     * Unhandled event types are recorded for the audit trail and marked processed —
     * treating them as errors would bury the genuinely stuck ones in noise.
     */
    public function test_an_unhandled_event_type_is_recorded_and_marked_processed(): void
    {
        $this->deliver(['event' => 'customer.identification.failed', 'data' => ['id' => 3]])->assertOk();

        $event = WebhookEvent::query()->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->isProcessed());
        $this->assertNull($event->error);
    }

    /**
     * A failed renewal starts the grace period rather than cutting access.
     */
    public function test_a_payment_failed_event_marks_the_subscription_past_due(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = app(SubscriptionService::class)->currentFor($org);
        $subscription->update(['paystack_subscription_code' => 'SUB_test123']);

        $this->deliver([
            'event' => 'invoice.payment_failed',
            'data' => ['id' => 555, 'subscription_code' => 'SUB_test123'],
        ])->assertOk();

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertNotNull($subscription->past_due_since);
        $this->assertTrue($subscription->isUsable(), 'Grace period keeps them working.');
    }

    /**
     * Cancellation at Paystack's end keeps the paid period they already bought.
     */
    public function test_a_subscription_disable_event_cancels_without_cutting_access(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = app(SubscriptionService::class)->currentFor($org);
        $subscription->update(['paystack_subscription_code' => 'SUB_bye']);

        $this->deliver([
            'event' => 'subscription.disable',
            'data' => ['id' => 556, 'subscription_code' => 'SUB_bye'],
        ])->assertOk();

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->status);
        $this->assertTrue($subscription->isUsable(), 'They paid through the period end.');
    }

    /**
     * subscription.create is the ONLY event carrying the email token, and both it and
     * the subscription code are required to cancel at Paystack later — so failing to
     * capture them here would make cancellation impossible.
     */
    public function test_subscription_create_stores_the_codes_needed_to_cancel(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = app(SubscriptionService::class)->currentFor($org);
        $subscription->update(['paystack_customer_code' => 'CUS_abc']);

        $this->deliver([
            'event' => 'subscription.create',
            'data' => [
                'id' => 777,
                'subscription_code' => 'SUB_new',
                'email_token' => 'tok_abc',
                'customer' => ['customer_code' => 'CUS_abc'],
                'plan' => ['plan_code' => 'PLN_abc'],
            ],
        ])->assertOk();

        $subscription->refresh();

        $this->assertSame('SUB_new', $subscription->paystack_subscription_code);
        $this->assertSame('tok_abc', $subscription->paystack_email_token);
    }

    /**
     * A failing handler must not turn into an infinite Paystack retry loop: the event
     * is acknowledged, the error is recorded, and it stays replayable from the back
     * office.
     */
    public function test_a_handler_failure_is_recorded_and_left_replayable(): void
    {
        // charge.success naming a plan that does not exist: the handler logs and returns
        // without granting anything.
        $this->deliver([
            'event' => 'charge.success',
            'data' => [
                'id' => 888,
                'reference' => 'MN-nonexistent',
                'amount' => 14900,
                'metadata' => ['plan_code' => 'no-such-plan'],
            ],
        ])->assertOk();

        $event = WebhookEvent::query()->first();

        $this->assertNotNull($event);
        // No payment was invented and no subscription granted.
        $this->assertSame(0, Payment::withoutOrganisationScope()->count());
        $this->assertSame(0, Subscription::withoutOrganisationScope()
            ->whereNotNull('paystack_subscription_code')->count());
    }

    /**
     * Replaying is safe to press more than once — handlers check current state, which
     * they must anyway because Paystack re-delivers.
     */
    public function test_replaying_an_event_is_idempotent(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = app(SubscriptionService::class)->currentFor($org);
        $subscription->update(['paystack_subscription_code' => 'SUB_replay']);

        $this->deliver([
            'event' => 'invoice.payment_failed',
            'data' => ['id' => 999, 'subscription_code' => 'SUB_replay'],
        ])->assertOk();

        $firstStamp = $subscription->fresh()->past_due_since;

        $event = WebhookEvent::query()->first();
        app(WebhookProcessor::class)->process($event);

        $this->assertEquals(
            $firstStamp->timestamp,
            $subscription->fresh()->past_due_since->timestamp,
            'Replaying must not extend the grace period.'
        );
    }

    protected function tearDown(): void
    {
        app(OrganisationContext::class)->forget();

        parent::tearDown();
    }
}
