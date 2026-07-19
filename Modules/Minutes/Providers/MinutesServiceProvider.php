<?php

namespace Modules\Minutes\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MinutesServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Minutes');
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath . '/Database/Migrations');
        $this->loadViewsFrom($this->modulePath . '/Resources/views', 'minutes');

        Route::middleware(['web', 'auth'])
            ->prefix('app')
            ->group($this->modulePath . '/Routes/web.php');
    }
}
