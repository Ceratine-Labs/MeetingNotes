<?php

namespace Modules\Auth\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Auth\Models\User;

class AuthServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Auth');
    }

    public function register(): void
    {
        // Point the framework's `users` provider at the module's User model.
        // config/auth.php still names App\Models\User (the untouched Laravel
        // scaffold); overriding here keeps module ownership of the model
        // without editing framework config.
        config(['auth.providers.users.model' => User::class]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath.'/Database/Migrations');
        $this->loadViewsFrom($this->modulePath.'/Resources/views', 'auth');
        $this->loadTranslationsFrom($this->modulePath.'/Resources/lang', 'auth');

        $this->registerRateLimiters();

        Route::middleware('web')->group($this->modulePath.'/Routes/web.php');

        /*
         * No `admin` middleware alias is registered here any more.
         *
         * It used to point at EnsureAdmin, which gated /app/admin/* on the legacy
         * `users.role` column. Back-office access is now the Admin module's
         * `admin.auth` alias against a separate guard and table, so both the
         * middleware and the column have been removed rather than left dormant.
         */
    }

    /**
     * Throttles for the public auth surface.
     *
     * Each credential limiter is keyed by BOTH the submitted email and the
     * client IP, and that combination is the point:
     *
     *   - Keying only on IP lets an attacker behind a rotating proxy pool spread
     *     a credential-stuffing run across thousands of addresses.
     *   - Keying only on email lets one attacker lock a known victim out of
     *     their own account by burning the limit deliberately.
     *
     * Two limits per endpoint catches both patterns while an ordinary user
     * mistyping their password a few times is unaffected.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            return [
                Limit::perMinute(5)->by($this->emailKey($request)),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Registration is deliberately tighter per IP than login: a legitimate
        // person creates one account, so anything beyond a handful an hour from
        // one address is free-tier farming.
        RateLimiter::for('register', function (Request $request): array {
            return [
                Limit::perMinute(3)->by($request->ip()),
                Limit::perHour(10)->by($request->ip()),
            ];
        });

        // These two SEND EMAIL to an address the requester names, so an
        // unthrottled endpoint is a mailbomb aimed at a third party — and it
        // burns our sending reputation, not just their inbox.
        RateLimiter::for('password-email', function (Request $request): array {
            return [
                Limit::perMinute(2)->by($this->emailKey($request)),
                Limit::perHour(10)->by($request->ip()),
            ];
        });

        RateLimiter::for('verification-resend', function (Request $request): Limit {
            // Keyed on the authenticated user — this route is behind `auth`, so
            // there is always one, and it is a stabler key than an IP on a
            // mobile network.
            return Limit::perMinute(2)->by((string) $request->user()?->getKey());
        });
    }

    /**
     * Normalised rate-limit key for a submitted email address.
     *
     * Lowercased so "Sam@acme.com" and "sam@acme.com" share one bucket —
     * otherwise case variation multiplies an attacker's allowance for free.
     */
    private function emailKey(Request $request): string
    {
        return mb_strtolower((string) $request->input('email')).'|'.$request->ip();
    }
}
