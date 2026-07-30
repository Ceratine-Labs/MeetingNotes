<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Exceptions\QuotaExceededException;
use Modules\Billing\Models\GenerationUsage;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\QuotaService;
use Modules\Tenancy\Services\OrganisationContext;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Generation metering — the enforcement point for the commercial model.
 *
 * A bug here either gives the product away (customers generating past their allowance)
 * or blocks people who have paid. Both are invisible until someone complains, which is
 * why they are asserted rather than reasoned about.
 */
class BillingQuotaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    private function quota(): QuotaService
    {
        return app(QuotaService::class);
    }

    public function test_a_new_free_workspace_starts_with_its_full_allowance(): void
    {
        [, $org] = $this->tenantUser(plan: $this->freePlan());

        $status = $this->quota()->statusFor($org);

        $this->assertSame(0, $status->used);
        $this->assertSame(3, $status->limit);
        $this->assertSame(3, $status->remaining());
        $this->assertTrue($status->allowsGeneration());
    }

    public function test_usage_is_summed_from_the_ledger(): void
    {
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        $this->quota()->recordUsage($org, userId: $user->getKey());
        $this->quota()->recordUsage($org, userId: $user->getKey());

        $status = $this->quota()->statusFor($org);

        $this->assertSame(2, $status->used);
        $this->assertSame(1, $status->remaining());
        $this->assertSame(2, GenerationUsage::withoutOrganisationScope()->count());
    }

    public function test_generation_is_blocked_once_the_allowance_is_spent(): void
    {
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        for ($i = 0; $i < 3; $i++) {
            $this->quota()->recordUsage($org, userId: $user->getKey());
        }

        $status = $this->quota()->statusFor($org);
        $this->assertSame(0, $status->remaining());
        $this->assertFalse($status->allowsGeneration());
        $this->assertFalse($this->quota()->canGenerate($org));

        $this->expectException(QuotaExceededException::class);
        $this->quota()->assertCanGenerate($org);
    }

    /**
     * The exception carries the status so the caller can name the actual limit and plan
     * rather than saying "quota exceeded" and leaving the customer guessing.
     */
    public function test_the_quota_exception_carries_the_status(): void
    {
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        for ($i = 0; $i < 3; $i++) {
            $this->quota()->recordUsage($org, userId: $user->getKey());
        }

        try {
            $this->quota()->assertCanGenerate($org);
            $this->fail('Expected QuotaExceededException.');
        } catch (QuotaExceededException $e) {
            $this->assertSame(3, $e->status->limit);
            $this->assertSame('Free', $e->status->planName);
            $this->assertNotNull($e->status->periodEnd);
        }
    }

    /**
     * null means unlimited, 0 means none. Conflating them would either give the product
     * away or block everyone — so it is asserted explicitly.
     */
    public function test_a_null_quota_means_unlimited_not_zero(): void
    {
        [$user, $org] = $this->tenantUser(plan: $this->unlimitedPlan());

        for ($i = 0; $i < 25; $i++) {
            $this->quota()->recordUsage($org, userId: $user->getKey());
        }

        $status = $this->quota()->statusFor($org);

        $this->assertTrue($status->isUnlimited());
        $this->assertNull($status->remaining());
        $this->assertTrue($status->allowsGeneration());
    }

    public function test_a_zero_quota_blocks_everything(): void
    {
        $plan = Plan::query()->create([
            'code' => 'test-none', 'name' => 'No Generations', 'price_cents' => 0,
            'currency' => 'ZAR', 'interval' => Plan::INTERVAL_MONTHLY,
            'generation_quota' => 0, 'seat_limit' => 1, 'is_active' => true, 'is_public' => false, 'sort' => 900,
        ]);
        [, $org] = $this->tenantUser(plan: $plan);

        $status = $this->quota()->statusFor($org);

        $this->assertFalse($status->isUnlimited());
        $this->assertSame(0, $status->remaining());
        $this->assertFalse($status->allowsGeneration());
    }

    /**
     * Rollover happens lazily on read, so a dead cron cannot freeze every customer's
     * quota at last month's usage.
     */
    public function test_an_elapsed_period_rolls_and_frees_the_allowance(): void
    {
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());
        $subscription = app(\Modules\Billing\Services\SubscriptionService::class)->currentFor($org);

        for ($i = 0; $i < 3; $i++) {
            $this->quota()->recordUsage($org, userId: $user->getKey());
        }
        $this->assertFalse($this->quota()->statusFor($org)->allowsGeneration());

        // Push the window into the past, as it would be a month later.
        $subscription->update([
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subMonth(),
        ]);

        $status = $this->quota()->statusFor($org);

        $this->assertSame(0, $status->used, 'Last period\'s usage must not count against the new one.');
        $this->assertTrue($status->allowsGeneration());
        $this->assertTrue($status->periodEnd->isFuture());

        // The ledger rows survive — they are the audit trail, not a counter.
        $this->assertSame(3, GenerationUsage::withoutOrganisationScope()->count());
    }

    /**
     * A lapsed subscription blocks generation even with credits showing: the
     * entitlement itself has ended.
     */
    public function test_a_lapsed_subscription_blocks_generation_and_downgrades(): void
    {
        $this->freePlan();
        [, $org] = $this->tenantUser(plan: $this->unlimitedPlan());
        $subscription = app(\Modules\Billing\Services\SubscriptionService::class)->currentFor($org);

        $subscription->update([
            'status' => Subscription::STATUS_PAST_DUE,
            'past_due_since' => now()->subDays((int) config('billing.grace_period_days') + 5),
        ]);

        // statusFor performs the lazy downgrade, so the workspace is limited rather
        // than locked out — it lands on free, not on nothing.
        $status = $this->quota()->statusFor($org);

        $this->assertSame('Free', $status->planName);
        $this->assertTrue($status->allowsGeneration(), 'Downgraded, not locked out.');
    }

    public function test_usage_is_scoped_to_the_workspace(): void
    {
        [$userA, $orgA] = $this->tenantUser(plan: $this->freePlan());
        [$userB, $orgB] = $this->tenantUser(plan: $this->freePlan());

        $this->quota()->recordUsage($orgA, userId: $userA->getKey());
        $this->quota()->recordUsage($orgA, userId: $userA->getKey());
        $this->quota()->recordUsage($orgB, userId: $userB->getKey());

        $this->assertSame(2, $this->quota()->statusFor($orgA)->used);
        $this->assertSame(1, $this->quota()->statusFor($orgB)->used);
    }

    public function test_the_warning_threshold_fires_before_exhaustion_but_not_after(): void
    {
        [$user, $org] = $this->tenantUser(plan: $this->freePlan());

        // 2 of 3 used — one third left, below the 20%... actually above it, so no warning yet.
        $this->quota()->recordUsage($org, userId: $user->getKey());
        $this->assertFalse($this->quota()->statusFor($org)->shouldWarn());

        // Exhausted: the hard upgrade prompt takes over, so the amber "running low"
        // warning is deliberately suppressed rather than shown alongside it.
        for ($i = 0; $i < 2; $i++) {
            $this->quota()->recordUsage($org, userId: $user->getKey());
        }
        $this->assertFalse($this->quota()->statusFor($org)->shouldWarn());
        $this->assertSame(0, $this->quota()->statusFor($org)->remaining());
    }

    protected function tearDown(): void
    {
        app(OrganisationContext::class)->forget();

        parent::tearDown();
    }
}
