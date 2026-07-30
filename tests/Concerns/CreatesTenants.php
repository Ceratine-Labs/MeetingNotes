<?php

namespace Tests\Concerns;

use Modules\Admin\Models\AdminUser;
use Modules\Auth\Models\User;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Minutes\Models\Meeting;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Models\Organisation;
use Modules\Tenancy\Services\OrganisationContext;

/**
 * Test helpers for the multi-tenant world.
 *
 * Before the SaaS conversion a test could create a bare user and hit `/app/*`. It now
 * needs a user, a workspace, a membership and a subscription, or the `organisation`
 * middleware redirects it to "create a workspace" and the assertion fails for a reason
 * that has nothing to do with what is being tested.
 *
 * This trait bundles that setup so each test says what it means in one line.
 *
 * Note on the organisation scope: it stands down in the `testing` environment (see
 * OrganisationScope::runningOutsideWebRequest), so direct model queries in a test are
 * unscoped and can set up fixtures freely. Requests made through the HTTP kernel DO go
 * through the middleware and ARE scoped — which is what makes an isolation test
 * meaningful.
 */
trait CreatesTenants
{
    /**
     * The free plan, created on first use.
     *
     * Required by anything that provisions a subscription. Created here rather than by
     * running PlanSeeder so a test does not depend on seeder ordering, and so the
     * values a test asserts against are visible in the test itself.
     */
    protected function freePlan(): Plan
    {
        return Plan::query()->firstOrCreate(
            ['code' => Plan::CODE_FREE],
            [
                'name' => 'Free',
                'price_cents' => 0,
                'currency' => 'ZAR',
                'interval' => Plan::INTERVAL_NONE,
                'generation_quota' => 3,
                'seat_limit' => 1,
                'features' => ['exports' => ['md']],
                'is_public' => true,
                'is_active' => true,
                'sort' => 10,
            ]
        );
    }

    /**
     * A plan with no limits, for tests that are not about metering.
     *
     * Quota and seat limits are NULL (unlimited), not large numbers — so a test that
     * generates repeatedly cannot fail on an allowance it was never testing.
     */
    protected function unlimitedPlan(): Plan
    {
        return Plan::query()->firstOrCreate(
            ['code' => 'test-unlimited'],
            [
                'name' => 'Test Unlimited',
                'price_cents' => 0,
                'currency' => 'ZAR',
                'interval' => Plan::INTERVAL_MONTHLY,
                'generation_quota' => null,
                'seat_limit' => null,
                'features' => ['exports' => ['md', 'docx', 'pdf'], 'custom_prompts' => true, 'api' => true],
                'is_public' => false,
                'is_active' => true,
                'sort' => 999,
            ]
        );
    }

    /**
     * A user who owns a workspace with an active subscription, ready to hit `/app/*`.
     *
     * Verified by default: most tests are not about the verification wall, and an
     * unverified user silently redirects away from the generation routes. Pass
     * `verified: false` to test the wall itself.
     *
     * @param  Plan|null  $plan  Defaults to the unlimited test plan, so a test only
     *         deals with quota when it means to.
     * @return array{0: User, 1: Organisation} The user and their workspace.
     */
    protected function tenantUser(
        ?Plan $plan = null,
        bool $verified = true,
        string $role = Membership::ROLE_OWNER,
    ): array {
        $plan ??= $this->unlimitedPlan();

        $user = User::query()->create([
            'name' => 'Test User',
            'email' => uniqid('user_', true).'@test.local',
            'password' => 'password-not-used',
        ]);

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $organisation = Organisation::query()->create([
            'name' => 'Test Workspace',
            'slug' => uniqid('ws-'),
            'owner_user_id' => $user->getKey(),
            'timezone' => 'Africa/Johannesburg',
        ]);

        Membership::query()->create([
            'organisation_id' => $organisation->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'joined_at' => now(),
        ]);

        $this->subscribe($organisation, $plan);

        // Set the pointer so OrganisationResolver takes the cheap path and binds this
        // workspace on the first request, rather than falling back to a join.
        $user->forceFill(['current_organisation_id' => $organisation->getKey()])->save();

        return [$user->refresh(), $organisation];
    }

    /**
     * Give a workspace an active subscription to a plan, snapshotting its entitlements.
     *
     * Mirrors SubscriptionService::start() rather than calling it, so a test can set up
     * a subscription state (past due, expired, mid-period) that the service would not
     * produce directly.
     */
    protected function subscribe(Organisation $organisation, Plan $plan, array $overrides = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'organisation_id' => $organisation->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => Subscription::STATUS_ACTIVE,
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            'price_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'generation_quota' => $plan->generation_quota,
            'seat_limit' => $plan->seat_limit,
            'features' => $plan->features,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ], $overrides));
    }

    /**
     * A meeting owned by a workspace, with a transcript.
     *
     * organisation_id is set explicitly rather than relying on the context, so a test
     * does not have to bind one just to create a fixture.
     */
    protected function tenantMeeting(User $user, Organisation $organisation, array $attributes = []): Meeting
    {
        $meeting = Meeting::query()->create(array_merge([
            'organisation_id' => $organisation->getKey(),
            'user_id' => $user->getKey(),
            'source_type' => 'paste',
            'status' => Meeting::STATUS_PROCESSING,
        ], $attributes));

        $meeting->transcript()->create(['raw_text' => 'Sarah: hello everyone…']);

        return $meeting;
    }

    /**
     * A back-office account, for hitting `/admin/*`.
     *
     * Use with `actingAs($admin, 'admin')` — the guard name matters, since the default
     * `web` guard has no standing in the back office at all.
     */
    protected function adminUser(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Test Admin',
            'email' => uniqid('admin_', true).'@test.local',
            'password' => 'password-not-used',
        ]);
    }

    /**
     * Bind a workspace for direct (non-HTTP) model access in a test.
     *
     * Only needed when asserting that the scope filters — model queries in the testing
     * environment are otherwise unscoped by design.
     */
    protected function actingForOrganisation(Organisation $organisation): void
    {
        app(OrganisationContext::class)->set($organisation);
    }
}
