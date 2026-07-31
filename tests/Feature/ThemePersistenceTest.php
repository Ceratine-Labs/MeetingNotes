<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Services\ThemeService;
use Modules\Tenancy\Services\OrganisationContext;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Theme resolution and persistence.
 *
 * Written after Ryan reported "light mode is not persisting". Two separate defects were
 * behind it, and both are asserted here so neither can come back:
 *
 * 1. The prefers-color-scheme fallback script decided for itself whether a preference
 *    existed by looking for the cookie in document.cookie. A signed-in user whose choice
 *    lived in `users.theme` but who had no cookie in that browser looked like a
 *    first-time visitor, so the script overrode their explicit choice with the operating
 *    system's. Toggling again did not help — nothing was wrong with the saving.
 *
 * 2. `mn_theme` was not excluded from Laravel's cookie encryption. Laravel discards any
 *    cookie it cannot decrypt, so the plaintext cookie written by JavaScript never
 *    reached PHP and a guest's choice was dropped on the very next request.
 */
class ThemePersistenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    public function test_light_is_the_default_for_a_visitor_with_no_preference(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-bs-theme="light"', false);
    }

    /**
     * The encryption defect. A plaintext cookie is what the browser writes, and PHP has
     * to be able to read it.
     */
    public function test_a_plaintext_theme_cookie_from_javascript_is_honoured(): void
    {
        $this->withUnencryptedCookie(ThemeService::COOKIE, 'dark')
            ->get('/login')
            ->assertOk()
            ->assertSee('data-bs-theme="dark"', false);
    }

    /**
     * The override defect, and the exact case Ryan hit: a saved preference, a browser
     * with no cookie, and a dark OS.
     */
    public function test_a_saved_user_preference_wins_and_suppresses_the_os_fallback(): void
    {
        [$user] = $this->tenantUser();
        $user->forceFill(['theme' => ThemeService::LIGHT])->save();

        $response = $this->actingAs($user)->get('/app/dashboard');

        $response->assertOk()->assertSee('data-bs-theme="light"', false);

        // The fallback script must not be on the page at all — if it is, it will
        // override the saved choice the moment the OS reports dark.
        $response->assertDontSee('prefers-color-scheme', false);
    }

    public function test_the_os_fallback_is_offered_only_when_nothing_is_stored(): void
    {
        [$user] = $this->tenantUser();
        $user->forceFill(['theme' => null])->save();

        $this->actingAs($user)->get('/app/dashboard')
            ->assertOk()
            ->assertSee('prefers-color-scheme', false);
    }

    public function test_saving_a_theme_persists_it_to_the_user_row(): void
    {
        [$user] = $this->tenantUser();
        $user->forceFill(['theme' => null])->save();

        $this->actingAs($user)
            ->postJson(route('core.theme.store'), ['theme' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'dark']);

        $this->assertSame('dark', $user->fresh()->theme);

        // fresh(): actingAs authenticates this exact in-memory instance, and persist()
        // writes with a targeted UPDATE rather than save(), so the in-memory copy is
        // stale. A real request loads the user from the session each time.
        // And the very next request renders it, with no fallback script to fight.
        $this->actingAs($user->fresh())->get('/app/dashboard')
            ->assertSee('data-bs-theme="dark"', false)
            ->assertDontSee('prefers-color-scheme', false);
    }

    public function test_switching_back_to_light_sticks(): void
    {
        [$user] = $this->tenantUser();
        $user->forceFill(['theme' => 'dark'])->save();

        $this->actingAs($user)->postJson(route('core.theme.store'), ['theme' => 'light'])->assertOk();

        $this->assertSame('light', $user->fresh()->theme);
        $this->actingAs($user->fresh())->get('/app/dashboard')
            ->assertSee('data-bs-theme="light"', false);
    }

    /**
     * The value lands in an HTML attribute, so anything unrecognised is rejected rather
     * than passed through.
     */
    public function test_an_unknown_theme_is_rejected(): void
    {
        [$user] = $this->tenantUser();

        $this->actingAs($user)
            ->postJson(route('core.theme.store'), ['theme' => '"><script>alert(1)</script>'])
            ->assertStatus(422);
    }

    protected function tearDown(): void
    {
        app(OrganisationContext::class)->forget();

        parent::tearDown();
    }
}
