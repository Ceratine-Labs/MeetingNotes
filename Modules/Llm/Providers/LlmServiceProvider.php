<?php

namespace Modules\Llm\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Llm\Services\LlmManager;

class LlmServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Llm');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath . '/Config/config.php', 'llm');
        $this->app->singleton(LlmManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath . '/Database/Migrations');
        $this->loadViewsFrom($this->modulePath . '/Resources/views', 'llm');

        Route::middleware(['web', 'auth', 'admin'])
            ->prefix('app/admin')
            ->group($this->modulePath . '/Routes/web.php');
    }
}
