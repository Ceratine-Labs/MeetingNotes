<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminAuthController;
use Modules\Admin\Http\Controllers\AuditLogController;
use Modules\Admin\Http\Controllers\DashboardController;
use Modules\Admin\Http\Controllers\OrganisationController;
use Modules\Admin\Http\Controllers\PaymentController;
use Modules\Admin\Http\Controllers\PlanController;
use Modules\Admin\Http\Controllers\UserController;
use Modules\Admin\Http\Controllers\WebhookEventController;

/*
|--------------------------------------------------------------------------
| Admin (SaaS back office) web routes
|--------------------------------------------------------------------------
|
| Registered under the /admin prefix, entirely separate from the customer-facing
| application. Everything below the login group requires the `admin.auth`
| middleware — the `admin` guard, NOT the customer `web` guard.
|
| Note the alias distinction: `admin.auth` is this module's guard check, while the
| bare `admin` alias is the legacy user-role gate in the Auth module. They are kept
| under different names so a route here cannot accidentally be protected by the
| weaker one.
|
*/

Route::prefix('admin')->group(function () {

    /*
    | Sign-in and the staff password reset flow.
    |
    | `guest:admin` — the guard matters. Plain `guest` checks the customer guard, so a
    | signed-in customer would be bounced away from the admin login page, which is
    | both wrong and a hint that the two are connected.
    */
    Route::middleware('guest:admin')->group(function () {
        Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
        Route::post('/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login')
            ->name('admin.login.attempt');

        Route::get('/forgot-password', [AdminAuthController::class, 'showLinkRequest'])
            ->name('admin.password.request');
        Route::post('/forgot-password', [AdminAuthController::class, 'sendLink'])
            ->middleware('throttle:admin-password-email')
            ->name('admin.password.email');

        Route::get('/reset-password/{token}', [AdminAuthController::class, 'showReset'])
            ->name('admin.password.reset');
        Route::post('/reset-password', [AdminAuthController::class, 'reset'])
            ->middleware('throttle:admin-password-email')
            ->name('admin.password.update');
    });

    /*
    | The back office itself.
    */
    Route::middleware('admin.auth')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

        Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

        // Customer workspaces
        Route::get('/organisations', [OrganisationController::class, 'index'])
            ->name('admin.organisations.index');
        Route::get('/organisations/{organisation}', [OrganisationController::class, 'show'])
            ->name('admin.organisations.show');
        Route::post('/organisations/{organisation}/plan', [OrganisationController::class, 'changePlan'])
            ->name('admin.organisations.plan');

        // Customer accounts
        Route::get('/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('admin.users.show');
        Route::post('/users/{user}/impersonate', [UserController::class, 'impersonate'])
            ->name('admin.users.impersonate');

        // Plans
        Route::get('/plans', [PlanController::class, 'index'])->name('admin.plans.index');
        Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('admin.plans.edit');
        Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('admin.plans.update');
        Route::post('/plans/{plan}/push', [PlanController::class, 'pushToGateway'])
            ->name('admin.plans.push');

        // Money
        Route::get('/payments', [PaymentController::class, 'index'])->name('admin.payments.index');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('admin.payments.show');
        Route::get('/subscriptions', [PaymentController::class, 'subscriptions'])
            ->name('admin.subscriptions.index');

        // Payment webhooks — inspect and replay
        Route::get('/webhooks', [WebhookEventController::class, 'index'])->name('admin.webhooks.index');
        Route::get('/webhooks/{event}', [WebhookEventController::class, 'show'])->name('admin.webhooks.show');
        Route::post('/webhooks/{event}/replay', [WebhookEventController::class, 'replay'])
            ->name('admin.webhooks.replay');

        // Who did what
        Route::get('/audit', [AuditLogController::class, 'index'])->name('admin.audit.index');
    });
});
