<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Exceptions\GatewayException;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\CheckoutService;
use Modules\Tenancy\Services\OrganisationContext;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Paystack gateway and checkout.
 *
 * All Paystack HTTP is faked. The point of these tests is the rules around the money,
 * not the wire format: that the browser callback is never trusted, that an amount
 * mismatch does not grant a subscription, and that a verified payment we cannot map to
 * a plan is still recorded rather than silently discarded.
 */
class BillingGatewayTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.enabled' => true,
            'billing.paystack.secret_key' => 'sk_test_gateway',
        ]);

        Http::preventStrayRequests();
    }

    /**
     * Paystack's response envelope.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(array $data): array
    {
        return ['status' => true, 'message' => 'ok', 'data' => $data];
    }

    public function test_initialising_a_transaction_returns_the_checkout_url(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response($this->envelope([
            'authorization_url' => 'https://checkout.paystack.com/abc123',
            'reference' => 'MN-test-ref',
        ]))]);

        $transaction = app(PaymentGateway::class)->initialiseTransaction(
            email: 'buyer@test.local',
            amountCents: 44900,
            reference: 'MN-test-ref',
            callbackUrl: 'https://app.test/callback',
        );

        $this->assertSame('https://checkout.paystack.com/abc123', $transaction->authorizationUrl);
        // Crucially NOT successful — they have only been given somewhere to pay.
        $this->assertFalse($transaction->successful);
    }

    /**
     * A 200 with `status: false` is a rejected request, independent of the HTTP code.
     */
    public function test_an_api_level_rejection_throws(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => false, 'message' => 'Invalid currency',
        ])]);

        $this->expectException(GatewayException::class);

        app(PaymentGateway::class)->initialiseTransaction(
            'buyer@test.local', 44900, 'MN-x', 'https://app.test/callback'
        );
    }

    public function test_verification_reports_a_failed_charge_as_unsuccessful(): void
    {
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response($this->envelope([
            'status' => 'failed',
            'reference' => 'MN-fail',
            'amount' => 44900,
            'gateway_response' => 'Insufficient funds',
        ]))]);

        $transaction = app(PaymentGateway::class)->verifyTransaction('MN-fail');

        $this->assertFalse($transaction->successful);
        $this->assertSame('Insufficient funds', $transaction->failureReason);
    }

    /**
     * Only the exact string 'success' counts — a truthy check would treat 'failed' as
     * paid.
     */
    public function test_only_an_exact_success_status_counts_as_paid(): void
    {
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response($this->envelope([
            'status' => 'pending', 'reference' => 'MN-pending', 'amount' => 44900,
        ]))]);

        $this->assertFalse(app(PaymentGateway::class)->verifyTransaction('MN-pending')->successful);
    }

    public function test_the_webhook_signature_check_is_exact(): void
    {
        $gateway = app(PaymentGateway::class);
        $body = '{"event":"charge.success"}';
        $valid = hash_hmac('sha512', $body, 'sk_test_gateway');

        $this->assertTrue($gateway->verifyWebhookSignature($body, $valid));
        $this->assertFalse($gateway->verifyWebhookSignature($body, $valid.'x'));
        $this->assertFalse($gateway->verifyWebhookSignature($body, null));
        // A changed body must invalidate the signature — this is what stops a forged
        // payload being replayed under a captured signature.
        $this->assertFalse($gateway->verifyWebhookSignature($body.' ', $valid));
    }

    /**
     * Recording intent BEFORE redirecting is what makes an abandoned checkout visible.
     */
    public function test_beginning_a_checkout_writes_a_pending_payment_first(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response($this->envelope([
            'authorization_url' => 'https://checkout.paystack.com/go',
        ]))]);

        $plan = $this->unlimitedPlan();
        $plan->update(['price_cents' => 44900, 'paystack_plan_code' => 'PLN_team']);
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        $url = app(CheckoutService::class)->begin($org, $plan, $user);

        $this->assertSame('https://checkout.paystack.com/go', $url);

        $payment = Payment::withoutOrganisationScope()->first();
        $this->assertNotNull($payment, 'An abandoned checkout must leave a trace.');
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(44900, $payment->amount_cents);
    }

    /**
     * A paid plan with no Paystack plan code would charge once and never renew.
     */
    public function test_a_plan_not_pushed_to_paystack_cannot_be_checked_out(): void
    {
        $plan = $this->unlimitedPlan();
        $plan->update(['price_cents' => 44900, 'paystack_plan_code' => null]);
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        $this->expectException(\DomainException::class);
        app(CheckoutService::class)->begin($org, $plan, $user);
    }

    /**
     * The attack the callback route exists to resist: anyone can visit it with an
     * invented reference.
     */
    public function test_a_callback_for_an_unknown_reference_grants_nothing(): void
    {
        [, $org] = $this->tenantUser(plan: $this->freePlan());

        $this->assertNull(app(CheckoutService::class)->complete('MN-never-issued'));
        $this->assertSame(0, Payment::withoutOrganisationScope()->count());
    }

    /**
     * An amount mismatch means tampering or a currency mix-up. It must be recorded as
     * failed and flagged, never activated.
     */
    public function test_an_amount_mismatch_does_not_grant_a_subscription(): void
    {
        $plan = $this->unlimitedPlan();
        $plan->update(['price_cents' => 44900, 'paystack_plan_code' => 'PLN_team']);
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response($this->envelope([
                'authorization_url' => 'https://checkout.paystack.com/go',
            ])),
        ]);
        app(CheckoutService::class)->begin($org, $plan, $user);
        $reference = Payment::withoutOrganisationScope()->first()->reference;

        // Paystack reports a much smaller amount than we asked for.
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response($this->envelope([
                'status' => 'success',
                'reference' => $reference,
                'amount' => 100,
                'metadata' => ['plan_code' => $plan->code],
            ])),
        ]);

        $this->assertNull(app(CheckoutService::class)->complete($reference));

        $payment = Payment::withoutOrganisationScope()->first();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertStringContainsString('mismatch', mb_strtolower($payment->failure_reason));
        $this->assertSame($this->freePlan()->code, app(\Modules\Billing\Services\SubscriptionService::class)
            ->currentFor($org)->plan_code, 'Still on free — no entitlement granted.');
    }

    public function test_a_verified_payment_activates_the_subscription(): void
    {
        $plan = $this->unlimitedPlan();
        $plan->update(['price_cents' => 44900, 'paystack_plan_code' => 'PLN_team']);
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        Http::fake(['api.paystack.co/transaction/initialize' => Http::response($this->envelope([
            'authorization_url' => 'https://checkout.paystack.com/go',
        ]))]);
        app(CheckoutService::class)->begin($org, $plan, $user);
        $reference = Payment::withoutOrganisationScope()->first()->reference;

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response($this->envelope([
            'status' => 'success',
            'reference' => $reference,
            'amount' => 44900,
            'channel' => 'card',
            'customer' => ['customer_code' => 'CUS_x'],
            'authorization' => ['last4' => '4242', 'brand' => 'visa'],
            'metadata' => ['plan_code' => $plan->code],
        ]))]);

        $subscription = app(CheckoutService::class)->complete($reference);

        $this->assertNotNull($subscription);
        $this->assertSame($plan->code, $subscription->plan_code);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);

        $payment = Payment::withoutOrganisationScope()->first();
        $this->assertSame(Payment::STATUS_SUCCESS, $payment->status);
        $this->assertSame('4242', $payment->card_last4, 'Only the last four digits are ever stored.');
        $this->assertNotNull($payment->paid_at);
    }

    /**
     * Money is never silently discarded: a verified payment we cannot map to a plan is
     * still recorded as successful, flagged for a human.
     */
    public function test_a_verified_payment_with_no_matching_plan_is_still_recorded(): void
    {
        $plan = $this->unlimitedPlan();
        $plan->update(['price_cents' => 44900, 'paystack_plan_code' => 'PLN_team']);
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        Http::fake(['api.paystack.co/transaction/initialize' => Http::response($this->envelope([
            'authorization_url' => 'https://checkout.paystack.com/go',
        ]))]);
        app(CheckoutService::class)->begin($org, $plan, $user);
        $reference = Payment::withoutOrganisationScope()->first()->reference;

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response($this->envelope([
            'status' => 'success',
            'reference' => $reference,
            'amount' => 44900,
            'metadata' => ['plan_code' => 'plan-that-was-deleted'],
        ]))]);

        $this->assertNull(app(CheckoutService::class)->complete($reference));

        $payment = Payment::withoutOrganisationScope()->first();
        $this->assertSame(Payment::STATUS_SUCCESS, $payment->status, 'The money arrived — record it.');
        $this->assertStringContainsString('review', mb_strtolower($payment->failure_reason));
    }

    /**
     * The webhook usually beats the browser back; a refreshed callback page must not
     * start a second subscription.
     */
    public function test_completing_an_already_successful_payment_is_idempotent(): void
    {
        $plan = $this->unlimitedPlan();
        $plan->update(['price_cents' => 44900, 'paystack_plan_code' => 'PLN_team']);
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());

        Payment::query()->create([
            'organisation_id' => $org->getKey(),
            'reference' => 'MN-already-done',
            'amount_cents' => 44900,
            'currency' => 'ZAR',
            'status' => Payment::STATUS_SUCCESS,
            'paid_at' => now(),
        ]);

        $before = Subscription::withoutOrganisationScope()->count();

        // No Http::fake for verify — if it tried to call out, preventStrayRequests
        // would fail the test, which is itself the assertion that it short-circuits.
        app(CheckoutService::class)->complete('MN-already-done');

        $this->assertSame($before, Subscription::withoutOrganisationScope()->count());
    }

    protected function tearDown(): void
    {
        app(OrganisationContext::class)->forget();

        parent::tearDown();
    }
}
