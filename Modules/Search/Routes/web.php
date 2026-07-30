<?php

use Illuminate\Support\Facades\Route;
use Modules\Search\Http\Controllers\SearchController;

/*
|--------------------------------------------------------------------------
| Search web routes
|--------------------------------------------------------------------------
|
| Both behind `auth` + `organisation`: search reads tenant-owned data, so the
| organisation must be bound before any query runs. The SearchDocument model's scope
| does the confining — see the SearchController docblock.
|
| The type-ahead endpoint is throttled. It fires on a debounce while someone types, so
| a stuck client or a script could otherwise hammer it; 60/minute is far above what the
| 1.5-second debounce can produce and far below what would hurt.
|
*/

Route::middleware(['auth', 'organisation'])->prefix('app')->group(function () {
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');

    Route::get('/search/quick', [SearchController::class, 'quick'])
        ->middleware('throttle:60,1')
        ->name('search.quick');
});
