<?php

use Illuminate\Support\Facades\Route;
use Modules\Minutes\Http\Controllers\ExportController;
use Modules\Minutes\Http\Controllers\MeetingController;
use Modules\Minutes\Http\Controllers\SectionController;

/*
|--------------------------------------------------------------------------
| Minutes web routes
|--------------------------------------------------------------------------
|
| Registered by MinutesServiceProvider under the 'web' middleware and the /app
| prefix.
|
| EVERY route here touches tenant-owned data, so every route carries the
| `organisation` middleware. That is not belt-and-braces: the OrganisationScope
| throws on an unbound context during a web request rather than falling back to
| unfiltered results, so a route added outside this group fails loudly in
| development instead of serving one customer another customer's minutes.
|
| `verified` sits on the two routes that spend money — see the comment on that
| group.
|
*/

Route::middleware(['auth', 'organisation'])->group(function () {

    /*
    | Reading and managing existing minutes. No email verification required: a
    | new customer should be able to look around, and these routes cost nothing
    | to serve.
    */
    Route::get('/minutes', [MeetingController::class, 'index'])->name('minutes.index');
    Route::get('/minutes/new', [MeetingController::class, 'create'])->name('minutes.create');
    Route::get('/minutes/{meeting}', [MeetingController::class, 'show'])->name('minutes.show');
    Route::get('/minutes/{meeting}/status', [MeetingController::class, 'status'])->name('minutes.status');
    Route::delete('/minutes/{meeting}', [MeetingController::class, 'destroy'])->name('minutes.destroy');

    Route::get('/minutes/{meeting}/export/{format}', [ExportController::class, 'download'])
        ->where('format', 'md|pdf|docx')
        ->name('minutes.export');

    /*
    | Hand editing and section regeneration. Free — correcting a document the
    | customer already paid a credit for is part of finishing it, not a new
    | generation.
    */
    Route::put('/minutes/{meeting}/sections/{section}', [SectionController::class, 'update'])
        ->name('minutes.sections.update');
    Route::post('/minutes/{meeting}/sections/{section}/regenerate', [SectionController::class, 'regenerate'])
        ->name('minutes.sections.regenerate');
    Route::get('/minutes/{meeting}/proposal', [SectionController::class, 'proposal'])
        ->name('minutes.proposal');
    Route::post('/minutes/{meeting}/proposal/accept', [SectionController::class, 'accept'])
        ->name('minutes.proposal.accept');
    Route::post('/minutes/{meeting}/proposal/discard', [SectionController::class, 'discard'])
        ->name('minutes.proposal.discard');

    /*
    | The two routes that start a full generation, and therefore spend real LLM
    | money.
    |
    | `verified` is applied here and nowhere else. Requiring a confirmed email
    | address before the first generation is what stops the free allowance being
    | farmed with throwaway addresses — while leaving the rest of the product
    | usable immediately, so verification does not feel like a gate for its own
    | sake. Quota itself is enforced deeper, in MinutesGenerator, so queued and
    | retried work is metered too.
    */
    Route::middleware('verified')->group(function () {
        Route::post('/minutes', [MeetingController::class, 'store'])->name('minutes.store');
        Route::post('/minutes/{meeting}/retry', [MeetingController::class, 'retry'])->name('minutes.retry');
    });
});
