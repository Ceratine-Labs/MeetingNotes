<?php

namespace Modules\Minutes\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MinutesServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
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

        // Only 'web' here — the route file declares 'auth', 'organisation' and
        // 'verified' itself, per group, because those differ between routes. Adding
        // 'auth' in both places worked but hid where authorisation is actually
        // decided.
        Route::middleware('web')
            ->prefix('app')
            ->group($this->modulePath . '/Routes/web.php');
    }
}
