<?php

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\ModuleRegistry;
use Modules\Core\Services\SeedMaster;
use Modules\Core\Services\SettingsService;

/**
 * Foundation provider. The ONLY module listed in bootstrap/providers.php —
 * every other module is discovered from its module.json and registered
 * here (house pattern: modules are self-contained and auto-discovered).
 */
class CoreServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Core');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath . '/Config/config.php', 'core');

        $this->app->singleton(SettingsService::class);
        $this->app->singleton(MenuService::class);
        $this->app->singleton(SeedMaster::class);

        // Auto-register every discovered module's providers, priority order.
        foreach (ModuleRegistry::providers() as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath . '/Database/Migrations');
        $this->loadViewsFrom($this->modulePath . '/Resources/views', 'core');
        $this->loadTranslationsFrom($this->modulePath . '/Resources/lang', 'core');

        Route::middleware('web')->prefix('app')->group($this->modulePath . '/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Core\Console\SeedMasterCommand::class,
            ]);
        }
    }
}
