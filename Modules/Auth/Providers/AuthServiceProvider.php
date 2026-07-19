<?php

namespace Modules\Auth\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Auth');
    }

    public function register(): void
    {
        config(['auth.providers.users.model' => \Modules\Auth\Models\User::class]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath . '/Database/Migrations');
        $this->loadViewsFrom($this->modulePath . '/Resources/views', 'auth');

        Route::middleware('web')->group($this->modulePath . '/Routes/web.php');

        $router = $this->app['router'];
        $router->aliasMiddleware('admin', \Modules\Auth\Http\Middleware\EnsureAdmin::class);
    }
}
