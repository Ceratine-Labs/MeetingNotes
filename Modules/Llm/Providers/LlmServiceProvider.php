<?php

namespace Modules\Llm\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Llm\Services\LlmManager;

class LlmServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
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

        // These screens are back-office tools, so they sit behind the Admin
        // module's `admin.auth` guard (the `admins` table), NOT the legacy
        // `admin` user-role alias they used before the SaaS conversion. The
        // prefix moved from /app/admin to /admin to match, and the Admin
        // sidebar links to them by route name.
        Route::middleware(['web', 'admin.auth'])
            ->prefix('admin')
            ->group($this->modulePath . '/Routes/web.php');
    }
}
