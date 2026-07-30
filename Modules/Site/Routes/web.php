<?php

use Illuminate\Support\Facades\Route;
use Modules\Site\Http\Controllers\SiteController;

/*
|--------------------------------------------------------------------------
| Site (public) web routes
|--------------------------------------------------------------------------
|
| Registered at the root — no /app prefix — because these are the pages a
| visitor and a search engine see. They are the only routes in the application
| that are deliberately indexable; head.blade.php defaults everything else to
| noindex, and each view here opts back in with @section('robots', 'index, follow').
|
| No `auth` and no `organisation` middleware. Nothing on these pages may read a
| tenant-owned model — see the SiteController docblock.
|
*/

Route::get('/', [SiteController::class, 'home'])->name('site.home');
Route::get('/manifest.webmanifest', [SiteController::class, 'manifest'])->name('site.manifest');
Route::get('/how-it-works', [SiteController::class, 'features'])->name('site.features');
Route::get('/pricing', [SiteController::class, 'pricing'])->name('site.pricing');
Route::get('/terms', [SiteController::class, 'terms'])->name('site.terms');
Route::get('/privacy', [SiteController::class, 'privacy'])->name('site.privacy');
