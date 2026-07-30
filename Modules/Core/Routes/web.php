<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\DashboardController;
use Modules\Core\Http\Controllers\ThemeController;

/*
|--------------------------------------------------------------------------
| Core web routes
|--------------------------------------------------------------------------
|
| Registered by CoreServiceProvider under the 'web' middleware and the /app
| prefix, so every path below is /app/… — the authenticated side of the
| product. Public marketing routes live in the Site module; the SaaS back
| office lives in Admin under its own prefix and guard.
|
*/

/*
| The dashboard needs the `organisation` middleware, not just `auth`.
|
| It renders the full app shell — DB-driven sidebar, workspace switcher, usage meter —
| and every one of those reads the bound organisation. Behind `auth` alone the context
| is empty, so all of them silently render nothing and the app looks half-built. Any
| new route that extends core::layouts.app belongs in this group.
*/
Route::middleware(['auth', 'organisation'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('core.dashboard');
});

/*
| Theme persistence.
|
| Deliberately outside the auth group. The layouts only hand this URL to the
| JS for signed-in users (a guest's cookie is written client-side and needs no
| round trip), but keeping the route open means a session that expires while
| the tab is open degrades to a harmless 200 instead of a 401 in the console —
| and ThemeService already no-ops the user-row write when there is no user.
*/
Route::post('/theme', [ThemeController::class, 'store'])->name('core.theme.store');
