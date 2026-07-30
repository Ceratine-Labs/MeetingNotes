<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Minutes\Models\Meeting;
use Modules\Tenancy\Models\Membership;
use Modules\Tenancy\Services\OrganisationContext;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Tenant isolation — the most security-relevant behaviour in the application.
 *
 * If any of these fail, one customer can read another customer's meeting minutes.
 * Treat a failure here as an incident, not a broken test.
 */
class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    public function test_a_workspace_only_sees_its_own_meetings_over_http(): void
    {
        [$userA, $orgA] = $this->tenantUser();
        [$userB, $orgB] = $this->tenantUser();

        $this->tenantMeeting($userA, $orgA, ['title' => 'Acme board meeting', 'status' => Meeting::STATUS_READY]);
        $this->tenantMeeting($userB, $orgB, ['title' => 'Globex strategy day', 'status' => Meeting::STATUS_READY]);

        $this->actingAs($userA)->get('/app/minutes')
            ->assertOk()
            ->assertSee('Acme board meeting')
            ->assertDontSee('Globex strategy day');

        // Fresh session before switching identity. AuthenticateSession stamps the
        // authenticated user's password hash into the session and re-checks it, so
        // reusing one session across two different users is correctly treated as a
        // hijacked session and evicted — which would show up here as a 302.
        $this->flushSession();

        $this->actingAs($userB)->get('/app/minutes')
            ->assertOk()
            ->assertSee('Globex strategy day')
            ->assertDontSee('Acme board meeting');
    }

    /**
     * The attack this scope exists to stop: guessing or leaking another workspace's
     * meeting id and requesting it directly.
     */
    public function test_another_workspaces_meeting_is_not_reachable_by_id(): void
    {
        [$userA] = $this->tenantUser();
        [$userB, $orgB] = $this->tenantUser();

        $theirMeeting = $this->tenantMeeting($userB, $orgB, ['status' => Meeting::STATUS_READY]);

        // 404, not 403 — the scope filters the row out entirely, so route-model
        // binding never finds it. That is the right answer: a 403 would confirm the
        // record exists.
        $this->actingAs($userA)->get("/app/minutes/{$theirMeeting->id}")->assertNotFound();
        $this->actingAs($userA)->delete("/app/minutes/{$theirMeeting->id}")->assertNotFound();
        $this->actingAs($userA)->get("/app/minutes/{$theirMeeting->id}/export/md")->assertNotFound();
    }

    public function test_organisation_id_is_stamped_from_the_bound_context_on_create(): void
    {
        [$user, $org] = $this->tenantUser();

        $this->actingForOrganisation($org);

        $meeting = Meeting::query()->create([
            'user_id' => $user->getKey(),
            'source_type' => 'paste',
            'status' => Meeting::STATUS_DRAFT,
        ]);

        $this->assertSame($org->getKey(), $meeting->organisation_id);
    }

    /**
     * A meeting with no organisation is invisible to everyone rather than visible to
     * everyone. This is what keeps pre-SaaS rows safe before `saas:backfill` runs.
     */
    public function test_a_meeting_with_no_organisation_is_invisible(): void
    {
        [$user, $org] = $this->tenantUser();

        Meeting::query()->create([
            'organisation_id' => null,
            'user_id' => $user->getKey(),
            'title' => 'Orphaned legacy meeting',
            'source_type' => 'paste',
            'status' => Meeting::STATUS_READY,
        ]);

        $this->actingAs($user)->get('/app/minutes')
            ->assertOk()
            ->assertDontSee('Orphaned legacy meeting');
    }

    public function test_withoutOrganisationScope_is_how_the_back_office_reads_across_tenants(): void
    {
        [$userA, $orgA] = $this->tenantUser();
        [$userB, $orgB] = $this->tenantUser();

        $this->tenantMeeting($userA, $orgA);
        $this->tenantMeeting($userB, $orgB);

        $this->actingForOrganisation($orgA);

        // Scoped: only workspace A's row.
        $this->assertSame(1, Meeting::query()->count());

        // Explicitly unscoped: both. Verbose by design, so switching isolation off is
        // visible at the call site.
        $this->assertSame(2, Meeting::withoutOrganisationScope()->count());
    }

    /**
     * Switching workspace must verify membership, not trust the id it was handed.
     */
    public function test_switching_into_a_workspace_you_do_not_belong_to_fails(): void
    {
        [$userA] = $this->tenantUser();
        [, $orgB] = $this->tenantUser();

        $this->actingAs($userA)
            ->post(route('tenancy.organisations.switch', $orgB))
            ->assertRedirect(route('core.dashboard'))
            ->assertSessionHas('error');

        $this->assertNotSame($orgB->getKey(), $userA->fresh()->current_organisation_id);
    }

    /**
     * The `current_organisation_id` pointer is a hint, not an authorisation. A user
     * removed from a workspace must not keep access just because their row still
     * points at it.
     */
    public function test_a_stale_workspace_pointer_does_not_grant_access(): void
    {
        [$userA, $orgA] = $this->tenantUser();
        [, $orgB] = $this->tenantUser();

        // Forge the pointer at a workspace they have no membership in.
        $userA->forceFill(['current_organisation_id' => $orgB->getKey()])->save();

        $this->actingAs($userA)->get('/app/minutes')->assertOk();

        // Resolution fell back to a workspace they actually belong to, and repaired
        // the pointer on the way.
        $this->assertSame($orgA->getKey(), $userA->fresh()->current_organisation_id);
    }

    public function test_member_role_cannot_reach_workspace_administration(): void
    {
        [$user] = $this->tenantUser(role: Membership::ROLE_MEMBER);

        $this->actingAs($user)->get('/app/workspace/members')->assertForbidden();
        $this->actingAs($user)->get('/app/workspace/settings')->assertForbidden();
    }

    public function test_workspace_admin_cannot_reach_billing_but_owner_can(): void
    {
        [$admin] = $this->tenantUser(role: Membership::ROLE_ADMIN);
        [$owner] = $this->tenantUser(role: Membership::ROLE_OWNER);

        // "Runs the workspace" and "controls the money" are deliberately separate.
        $this->actingAs($admin)->get('/app/billing')->assertForbidden();

        $this->flushSession();

        $this->actingAs($owner)->get('/app/billing')->assertOk();
    }

    protected function tearDown(): void
    {
        // The context is a singleton; leaving a tenant bound would leak into the next
        // test in the same process, which is the same class of bug BindOrganisation
        // guards against in queue workers.
        app(OrganisationContext::class)->forget();

        parent::tearDown();
    }
}
