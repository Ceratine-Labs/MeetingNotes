<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The public marketing pages.
 *
 * Replaces the scaffold ExampleTest, which asserted that `/` redirects to the
 * dashboard. That stopped being true in the SaaS conversion: `/` is now the landing
 * page, and a visitor who has never signed up has to be able to read it.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    /**
     * The landing page renders the plan strip, so the plans table must exist and be
     * populated — hence freePlan() rather than an empty database.
     */
    public function test_landing_page_renders_for_a_guest(): void
    {
        $this->freePlan();

        $this->get('/')
            ->assertOk()
            ->assertSee(config('app.name'))
            ->assertSee('Start free');
    }

    public function test_pricing_renders_plans_from_the_database(): void
    {
        $this->freePlan();

        $this->get('/pricing')
            ->assertOk()
            // Rendered from the plans table, not hardcoded in the view — so an admin
            // price change shows on the public site immediately.
            ->assertSee('Free')
            ->assertSee('3');
    }

    public function test_supporting_public_pages_render(): void
    {
        $this->freePlan();

        foreach (['/how-it-works', '/terms', '/privacy'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    /**
     * The public pages are the only indexable ones — everything else defaults to
     * noindex in head.blade.php. Getting this backwards either hides the marketing
     * site from search or exposes the application to it.
     */
    public function test_public_pages_are_indexable_and_the_app_is_not(): void
    {
        $this->freePlan();

        $this->get('/')->assertSee('name="robots" content="index, follow"', false);

        $this->get('/login')->assertSee('name="robots" content="noindex, nofollow"', false);
    }

    /**
     * A signed-in customer visiting a public page is offered the app rather than being
     * asked to register again — someone following a pricing link from an email should
     * not be treated as a stranger.
     */
    public function test_signed_in_customer_sees_a_link_into_the_app(): void
    {
        $this->freePlan();
        [$user] = $this->tenantUser();

        $this->actingAs($user)->get('/pricing')
            ->assertOk()
            ->assertSee('Open the app');
    }

    public function test_app_and_back_office_send_guests_to_their_own_logins(): void
    {
        $this->get('/app/dashboard')->assertRedirect(route('auth.login'));
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }
}
