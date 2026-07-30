<?php

namespace Modules\Admin\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Admin\Http\Middleware\AuthenticateAdmin;
use Modules\Admin\Models\AdminUser;
use Modules\Admin\Services\AuditLogger;

/**
 * Admin (SaaS back office) module provider.
 *
 * Priority 95 — last, because it reports on and manages every other module's data
 * and needs them all registered first.
 *
 * The guard, provider and password broker are configured here rather than in
 * config/auth.php so the module stays self-contained: deleting this directory
 * removes the back office entirely, leaving no dangling config referencing classes
 * that no longer exist.
 */
class AdminServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Admin');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath.'/Config/config.php', 'admin');

        $this->app->singleton(AuditLogger::class);

        $this->registerGuard();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath.'/Database/Migrations');
        $this->loadViewsFrom($this->modulePath.'/Resources/views', 'admin');
        $this->loadTranslationsFrom($this->modulePath.'/Resources/lang', 'admin');

        $this->registerRateLimiters();

        Route::middleware('web')->group($this->modulePath.'/Routes/web.php');

        // `admin.auth` — deliberately NOT `admin`, which is the legacy user-role gate
        // in the Auth module. Distinct names so a route cannot be protected by the
        // weaker one through a copy-paste.
        $this->app['router']->aliasMiddleware('admin.auth', AuthenticateAdmin::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Admin\Console\CreateAdminCommand::class,
            ]);
        }
    }

    /**
     * Register the `admin` guard, its user provider, and its password broker.
     *
     * All three are separate from the customer equivalents:
     *
     *   - **guard** `admin` — a session on this guard is not a session on `web`, so
     *     no customer session can ever satisfy an admin check.
     *   - **provider** `admins` — resolves AdminUser from the `admins` table.
     *   - **broker** `admins` — issues reset tokens into
     *     `admin_password_reset_tokens`. A shared table would let a staff token and a
     *     customer token collide on the same email address, and each broker would
     *     happily consider the other's token.
     *
     * Expiry is 30 minutes rather than Laravel's default 60: a reset link that grants
     * back-office access should have a shorter window.
     */
    private function registerGuard(): void
    {
        config([
            'auth.guards.admin' => [
                'driver' => 'session',
                'provider' => 'admins',
            ],

            'auth.providers.admins' => [
                'driver' => 'eloquent',
                'model' => AdminUser::class,
            ],

            'auth.passwords.admins' => [
                'provider' => 'admins',
                'table' => 'admin_password_reset_tokens',
                'expire' => 30,
                'throttle' => 60,
            ],
        ]);
    }

    /**
     * Throttles for the back-office login surface.
     *
     * Tighter than the customer equivalents — three attempts a minute versus five.
     * There are two legitimate users of this endpoint, so a genuine person hitting a
     * low limit is a rare inconvenience, while anyone else hitting it is an attacker
     * being slowed down.
     *
     * Keyed on email plus IP for the same reason as the customer limiters: IP alone
     * is defeated by a proxy pool, email alone lets an attacker lock a known
     * administrator out of their own account.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('admin-login', function (Request $request): array {
            return [
                Limit::perMinute(3)->by($this->emailKey($request)),
                Limit::perMinute(10)->by($request->ip()),
            ];
        });

        RateLimiter::for('admin-password-email', function (Request $request): array {
            return [
                Limit::perMinute(2)->by($this->emailKey($request)),
                Limit::perHour(5)->by($request->ip()),
            ];
        });
    }

    /**
     * Normalised rate-limit key combining the submitted email and the client IP.
     */
    private function emailKey(Request $request): string
    {
        return 'admin|'.mb_strtolower((string) $request->input('email')).'|'.$request->ip();
    }
}
