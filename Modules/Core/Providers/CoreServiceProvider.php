<?php

namespace Modules\Core\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\AssetService;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\ModuleRegistry;
use Modules\Core\Services\SeedMaster;
use Modules\Core\Services\SettingsService;
use Modules\Core\Services\ThemeService;

/**
 * Foundation provider. The ONLY module listed in bootstrap/providers.php —
 * every other module is discovered from its module.json and registered
 * here (house pattern: modules are self-contained and auto-discovered).
 */
class CoreServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Core');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath.'/Config/config.php', 'core');

        $this->app->singleton(SettingsService::class);
        $this->app->singleton(MenuService::class);
        $this->app->singleton(SeedMaster::class);
        $this->app->singleton(ThemeService::class);
        $this->app->singleton(AssetService::class);

        // Auto-register every discovered module's providers, priority order.
        foreach (ModuleRegistry::providers() as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath.'/Database/Migrations');
        $this->loadViewsFrom($this->modulePath.'/Resources/views', 'core');
        $this->loadTranslationsFrom($this->modulePath.'/Resources/lang', 'core');

        Route::middleware('web')->prefix('app')->group($this->modulePath.'/Routes/web.php');

        $this->composeLayouts();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Core\Console\SeedMasterCommand::class,
            ]);
        }
    }

    /**
     * Inject the variables every shell layout needs.
     *
     * A view composer rather than middleware or a base controller, because the
     * layouts are shared across four modules (Core, Auth, Site, Admin) whose
     * controllers have nothing else in common. This way a new module can just
     * `@extends('core::layouts.app')` and the shell works — no boilerplate to
     * remember, and no way to forget it and hit an undefined-variable error in
     * production on a page nobody tested.
     *
     * Split by layout on purpose so no page pays for data it will not render:
     * only the app shell has a sidebar, so only it queries the menu.
     */
    private function composeLayouts(): void
    {
        // $theme — resolved server-side so the very first paint is correct.
        View::composer(
            ['core::layouts.app', 'core::layouts.guest', 'core::layouts.marketing', 'admin::layouts.app'],
            function (ViewContract $view): void {
                $themes = app(ThemeService::class);

                $view->with([
                    'theme' => $themes->current(request()),
                    // Drives whether head.blade.php emits the prefers-color-scheme
                    // fallback at all. The server knows; the script must not guess.
                    'hasThemePreference' => $themes->hasExplicitPreference(request()),
                ]);
            }
        );

        // $menu — DB-driven sidebar entries, filtered to the viewer's workspace role.
        View::composer('core::layouts.app', function (ViewContract $view): void {
            $view->with('menu', app(MenuService::class)->visibleFor(
                request()->user(),
                $this->currentWorkspaceRole()
            ));
        });
    }

    /**
     * The signed-in user's role in their current workspace, or null.
     *
     * Resolved here rather than inside MenuService so that Core — the foundation
     * module — keeps no compile-time dependency on Tenancy. The reference is soft
     * (a class_exists check plus a container lookup), so Core still boots and
     * renders if Tenancy is absent; every menu entry carrying a `required_role`
     * simply stays hidden, which is the correct fail-closed direction.
     *
     * @return string|null One of owner|admin|member.
     */
    private function currentWorkspaceRole(): ?string
    {
        $contextClass = \Modules\Tenancy\Services\OrganisationContext::class;

        if (! class_exists($contextClass)) {
            return null;
        }

        $user = request()->user();

        if ($user === null) {
            return null;
        }

        $organisation = app($contextClass)->get();

        return $organisation?->membershipFor($user)?->role;
    }
}
