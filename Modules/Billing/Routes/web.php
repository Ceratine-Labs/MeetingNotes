<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\BillingController;
use Modules\Billing\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Billing web routes
|--------------------------------------------------------------------------
|
| Three protection levels, and the differences are all deliberate.
|
*/

/*
| 1. The Paystack webhook.
|
| No auth, no session, no CSRF token — the caller is Paystack's server, not a
| browser. Authenticity is the HMAC signature over the raw body, verified in the
| controller before the payload is trusted.
|
| `withoutMiddleware` on the CSRF verifier is what allows a POST with no token.
| It is scoped to this one route and nothing else.
*/
Route::post('/webhooks/paystack', [WebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('billing.webhook.paystack');

/*
| 2. The payment return URL.
|
| Signed in and inside a workspace, but NOT owner-gated: whoever completes a
| payment lands here, and re-checking the role after the money has moved could
| strand a confirmed payment behind a 403. The reference is verified server-side
| regardless of who is looking at the page.
*/
Route::middleware(['auth', 'organisation'])
    ->prefix('app/billing')
    ->group(function () {
        Route::get('/callback', [BillingController::class, 'callback'])->name('billing.callback');
    });

/*
| 3. Managing the subscription — owner only.
|
| Deliberately owner and not admin: "runs the workspace" and "controls the money"
| are different jobs at a customer, so an office manager can administer members
| without being able to change the plan or see the card details.
*/
Route::middleware(['auth', 'organisation', 'organisation.role:owner'])
    ->prefix('app/billing')
    ->group(function () {
        Route::get('/', [BillingController::class, 'index'])->name('billing.index');
        Route::get('/plans', [BillingController::class, 'plans'])->name('billing.plans');
        Route::post('/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
        Route::post('/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    });
