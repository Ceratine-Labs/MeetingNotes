<?php

namespace Modules\Tenancy\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Contracts\SeatLimitProvider;
use Modules\Tenancy\Http\Middleware\EnsureOrganisation;
use Modules\Tenancy\Http\Middleware\EnsureOrganisationRole;
use Modules\Tenancy\Services\OrganisationContext;
use Modules\Tenancy\Services\UnlimitedSeats;

/**
 * Tenancy module provider.
 *
 * Priority 5 in module.json — after Core, before everything that stores tenant
 * data. That ordering matters: OrganisationContext must be a registered
 * singleton before any model using BelongsToOrganisation is booted, or the
 * global scope resolves a fresh (empty) context per query.
 */
class TenancyServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Tenancy');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath.'/Config/config.php', 'tenancy');

        /*
         * A singleton, so it is one instance per request (web) or per process
         * (queue worker). Anything transient here would silently break the
         * global scope: each query would resolve a new, empty context and the
         * organisation filter would never be applied.
         *
         * The queue-worker consequence is the reason the BindOrganisation job
         * middleware exists — a long-lived worker must not inherit the previous
         * job's tenant. See OrganisationContext::forget().
         */
        $this->app->singleton(OrganisationContext::class);

        /*
         * Default seat-limit implementation: unlimited. Billing rebinds this to
         * its plan-aware version when that module is present, which is what
         * keeps Tenancy independent of Billing (see the SeatLimitProvider
         * interface docblock).
         */
        $this->app->bind(SeatLimitProvider::class, UnlimitedSeats::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath.'/Database/Migrations');
        $this->loadViewsFrom($this->modulePath.'/Resources/views', 'tenancy');
        $this->loadTranslationsFrom($this->modulePath.'/Resources/lang', 'tenancy');

        Route::middleware('web')->group($this->modulePath.'/Routes/web.php');

        $router = $this->app['router'];
        $router->aliasMiddleware('organisation', EnsureOrganisation::class);
        $router->aliasMiddleware('organisation.role', EnsureOrganisationRole::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Tenancy\Console\BackfillOrganisationsCommand::class,
            ]);
        }
    }
}
