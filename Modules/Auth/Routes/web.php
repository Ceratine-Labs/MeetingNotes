<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\EmailVerificationController;
use Modules\Auth\Http\Controllers\PasswordResetController;
use Modules\Auth\Http\Controllers\ProfileController;
use Modules\Auth\Http\Controllers\RegisterController;

/*
|--------------------------------------------------------------------------
| Auth web routes (end users — the `web` guard)
|--------------------------------------------------------------------------
|
| The SaaS back office has an entirely separate set under /admin (see
| Modules/Admin). Nothing here touches the `admin` guard.
|
| Rate limiters referenced below are defined in AuthServiceProvider::boot().
| Every unauthenticated endpoint that either checks a credential or sends an
| email is throttled — those are the two ways this surface gets abused
| (credential stuffing, and using our mail server to spam a third party).
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('auth.register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:register')
        ->name('auth.register.store');

    Route::get('/login', [AuthController::class, 'showLogin'])->name('auth.login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login.attempt');

    Route::get('/forgot-password', [PasswordResetController::class, 'showLinkRequest'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:password-email')
        ->name('password.email');

    /*
    | Route name MUST stay `password.reset` — Laravel's ResetPassword
    | notification builds the emailed link from that exact name. Renaming it
    | breaks every reset email with a route-not-defined error.
    */
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])
        ->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-email')
        ->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    /*
    | Email verification.
    |
    | `verification.notice` and `verification.verify` are framework-recognised
    | names: the `verified` middleware redirects to the first, and the VerifyEmail
    | notification signs a URL for the second. Do not rename them.
    */
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('/email/verify/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification-resend')
        ->name('verification.send');

    /*
    | Profile renders the full app shell (sidebar, workspace switcher, usage meter), all
    | of which read the bound organisation — so it needs `organisation` as well as
    | `auth`. Without it those partials render blank.
    */
    Route::middleware('organisation')->prefix('app')->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('auth.profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('auth.profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
            ->name('auth.profile.password');
    });
});
