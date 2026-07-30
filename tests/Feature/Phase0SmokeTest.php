<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Core\Models\SeedRegistry;
use Modules\Core\Services\SeedMaster;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class Phase0SmokeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/app/dashboard')->assertRedirect(route('auth.login'));
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('MeetingNotes');
    }

    public function test_user_can_log_in_and_reach_dashboard(): void
    {
        // tenantUser() gives them a workspace and a subscription — without those the
        // `organisation` middleware redirects them to "create a workspace" and the
        // dashboard assertion would fail for an unrelated reason.
        [$user] = $this->tenantUser();

        $user->forceFill(['password' => 'secret-password-1'])->save();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ])->assertRedirect(route('core.dashboard'));

        $this->actingAs($user)
            ->get('/app/dashboard')
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_users_get_uuid_primary_keys(): void
    {
        $user = User::query()->create([
            'name' => 'UUID Check',
            'email' => 'uuid@test.local',
            'password' => 'x'
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $user->id
        );
    }

    public function test_seed_master_never_runs_a_seeder_twice(): void
    {
        $master = app(SeedMaster::class);

        [$ranFirst] = $master->run();
        [$ranSecond, $skippedSecond] = $master->run();

        $this->assertNotEmpty($ranFirst, 'first run should execute seeders');
        $this->assertSame([], $ranSecond, 'second run must execute nothing');
        $this->assertSame(count($ranFirst), count($skippedSecond));

        // Ledger holds exactly one row per seeder.
        $this->assertSame(
            count($ranFirst),
            SeedRegistry::query()->count()
        );
    }

    public function test_seed_master_force_appends_a_new_batch_row(): void
    {
        $master = app(SeedMaster::class);
        $master->run();

        $class = \Modules\Core\Database\Seeders\CoreMenuSeeder::class;
        $master->force($class);

        $this->assertSame(
            2,
            SeedRegistry::query()->where('seeder_class', $class)->count(),
            'force run must append, not rewrite'
        );
    }
}
