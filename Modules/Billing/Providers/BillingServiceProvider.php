<?php

namespace Modules\Billing\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Gateways\PaystackGateway;
use Modules\Billing\Listeners\ProvisionFreeSubscription;
use Modules\Billing\Services\PlanSeatLimits;
use Modules\Tenancy\Contracts\SeatLimitProvider;
use Modules\Tenancy\Events\OrganisationCreated;

/**
 * Billing module provider.
 *
 * Priority 25 — after Tenancy (5), because it overrides one of Tenancy's
 * bindings and listens to its events, and before Minutes (30), which asks the
 * quota service for permission before generating.
 */
class BillingServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Billing');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath.'/Config/config.php', 'billing');

        // The only place Paystack is named. Swapping or adding a PSP is a change
        // to this line plus a new gateway class.
        $this->app->bind(PaymentGateway::class, PaystackGateway::class);

        /*
         * Replace Tenancy's UnlimitedSeats default with the plan-aware version.
         *
         * This is the crossing point between the two modules, and it only works
         * because Tenancy resolves SeatLimitProvider from the container at use
         * time rather than constructing one. Tenancy still boots correctly with
         * Billing removed — it simply keeps its own unlimited default.
         */
        $this->app->bind(SeatLimitProvider::class, PlanSeatLimits::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath.'/Database/Migrations');
        $this->loadViewsFrom($this->modulePath.'/Resources/views', 'billing');
        $this->loadTranslationsFrom($this->modulePath.'/Resources/lang', 'billing');

        Route::middleware('web')->group($this->modulePath.'/Routes/web.php');

        // Every new workspace gets the free plan. Registered here rather than in a
        // global EventServiceProvider so the wiring lives with the module that
        // owns the behaviour.
        Event::listen(OrganisationCreated::class, ProvisionFreeSubscription::class);
    }
}
