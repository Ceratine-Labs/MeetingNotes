<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\SubscriptionService;
use Modules\Tenancy\Events\OrganisationCreated;
use Modules\Tenancy\Services\OrganisationContext;
use Modules\Tenancy\Services\OrganisationService;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Subscription lifecycle.
 *
 * The invariant everything else depends on: **an organisation has exactly one live
 * subscription at a time.** Two live subscriptions make "what is this customer
 * entitled to?" ambiguous, and the quota check would pick whichever the database
 * happened to return first.
 */
class BillingSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    private function subscriptions(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    /**
     * The whole registration promise: a new workspace is usable immediately, with no
     * card and no Paystack call.
     */
    public function test_creating_an_organisation_provisions_the_free_plan(): void
    {
        $this->freePlan();

        $user = \Modules\Auth\Models\User::query()->create([
            'name' => 'New Customer',
            'email' => uniqid('new_', true).'@test.local',
            'password' => 'irrelevant',
        ]);

        // Through the service, so OrganisationCreated fires and Billing's listener runs
        // — this is the seam being tested, not just the service call.
        $organisation = app(OrganisationService::class)->create('Brand New Co', $user);

        $subscription = $this->subscriptions()->currentFor($organisation);

        $this->assertNotNull($subscription, 'A new workspace must land on a plan.');
        $this->assertSame(Plan::CODE_FREE, $subscription->plan_code);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->isUsable());
    }

    /**
     * A duplicated or replayed OrganisationCreated event must not create a second
     * subscription — and must never downgrade a customer who has since upgraded.
     */
    public function test_provisioning_free_twice_is_idempotent(): void
    {
        $this->freePlan();
        [, $org] = $this->tenantUser(plan: $this->freePlan());

        $first = $this->subscriptions()->provisionFree($org);
        $second = $this->subscriptions()->provisionFree($org);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Subscription::withoutOrganisationScope()
            ->where('organisation_id', $org->getKey())->live()->count());
    }

    public function test_a_paid_subscription_is_not_downgraded_by_a_replayed_event(): void
    {
        $this->freePlan();
        $team = $this->unlimitedPlan();
        [, $org] = $this->tenantUser(plan: $team);

        // Simulates the event firing again after they upgraded.
        OrganisationCreated::dispatch($org);

        $this->assertSame(
            $team->code,
            $this->subscriptions()->currentFor($org)->plan_code,
            'A replayed OrganisationCreated must not knock a paying customer back to free.'
        );
    }

    /**
     * Starting a new subscription must supersede the previous one in the same
     * transaction — never leave two live.
     */
    public function test_starting_a_plan_supersedes_the_previous_subscription(): void
    {
        $free = $this->freePlan();
        $paid = $this->unlimitedPlan();
        [, $org] = $this->tenantUser(plan: $free);

        $this->subscriptions()->start($org, $paid);

        $live = Subscription::withoutOrganisationScope()
            ->where('organisation_id', $org->getKey())->live()->get();

        $this->assertCount(1, $live);
        $this->assertSame($paid->code, $live->first()->plan_code);

        // The old one is kept as billing history, not deleted.
        $this->assertSame(2, Subscription::withoutOrganisationScope()
            ->where('organisation_id', $org->getKey())->count());
    }

    /**
     * The reason entitlements are snapshotted: editing a plan must not retroactively
     * change what an existing customer already agreed to.
     */
    public function test_editing_a_plan_does_not_change_an_existing_subscription(): void
    {
        $plan = $this->freePlan();
        [, $org] = $this->tenantUser(plan: $plan);

        $plan->update(['price_cents' => 99900, 'generation_quota' => 1]);

        $subscription = $this->subscriptions()->currentFor($org);

        $this->assertSame(0, $subscription->price_cents, 'Price is snapshotted at subscribe time.');
        $this->assertSame(3, $subscription->generation_quota, 'Quota is snapshotted at subscribe time.');
    }

    /**
     * A failed renewal starts a grace period rather than cutting access — a card that
     * expired over a weekend should not read as churn.
     */
    public function test_a_failed_renewal_keeps_access_through_the_grace_period(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = $this->subscriptions()->currentFor($org);

        $this->subscriptions()->markPastDue($subscription);
        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertTrue($subscription->isUsable(), 'Access continues inside the grace window.');
        $this->assertTrue($subscription->withinGracePeriod());
    }

    public function test_past_due_beyond_the_grace_period_is_no_longer_usable(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = $this->subscriptions()->currentFor($org);

        $subscription->update([
            'status' => Subscription::STATUS_PAST_DUE,
            'past_due_since' => now()->subDays((int) config('billing.grace_period_days') + 1),
        ]);

        $this->assertFalse($subscription->fresh()->isUsable());
    }

    /**
     * Repeated failures must not keep extending the window.
     */
    public function test_marking_past_due_twice_does_not_extend_the_grace_period(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = $this->subscriptions()->currentFor($org);

        $this->subscriptions()->markPastDue($subscription);
        $firstStamp = $subscription->fresh()->past_due_since;

        $this->travel(2)->days();
        $this->subscriptions()->markPastDue($subscription->fresh());

        $this->assertEquals(
            $firstStamp->timestamp,
            $subscription->fresh()->past_due_since->timestamp,
            'past_due_since is stamped once, on the first failure.'
        );
    }

    /**
     * Cancelling keeps what the customer paid for.
     */
    public function test_cancelling_keeps_access_until_the_period_ends(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = $this->subscriptions()->currentFor($org);

        $this->subscriptions()->cancel($subscription);
        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertTrue($subscription->isUsable(), 'They paid for the current period.');
    }

    /**
     * And the downgrade lands once that paid period has actually elapsed — without
     * deleting anything.
     */
    public function test_an_elapsed_cancelled_subscription_downgrades_to_free(): void
    {
        $this->freePlan();
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = $this->subscriptions()->currentFor($org);

        $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

        $result = $this->subscriptions()->downgradeIfElapsed($subscription->fresh());

        $this->assertNotNull($result);
        $this->assertSame(Plan::CODE_FREE, $result->plan_code);
        $this->assertSame(Plan::CODE_FREE, $this->subscriptions()->currentFor($org)->plan_code);
    }

    public function test_a_usable_subscription_is_not_downgraded(): void
    {
        $this->freePlan();
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());

        $this->assertNull(
            $this->subscriptions()->downgradeIfElapsed($this->subscriptions()->currentFor($org))
        );
    }

    /**
     * Periods must stay contiguous, and rolling must catch up if the account sat idle —
     * otherwise one roll leaves the window still in the past and the quota reads as
     * exhausted forever.
     */
    public function test_rolling_the_period_is_contiguous_and_catches_up(): void
    {
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = $this->subscriptions()->currentFor($org);

        $oldEnd = now()->subMonths(3)->startOfDay();
        $subscription->update([
            'current_period_start' => $oldEnd->copy()->subMonth(),
            'current_period_end' => $oldEnd,
        ]);

        $rolled = $this->subscriptions()->rollPeriod($subscription->fresh());

        $this->assertTrue(
            $rolled->current_period_end->isFuture(),
            'Rolling must catch up past several elapsed periods, not just one.'
        );
        $this->assertTrue(
            $rolled->current_period_start->greaterThanOrEqualTo($oldEnd),
            'The new window starts where an old one ended — periods stay contiguous.'
        );
    }

    protected function tearDown(): void
    {
        app(OrganisationContext::class)->forget();

        parent::tearDown();
    }
}
