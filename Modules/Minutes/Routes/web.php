<?php

use Illuminate\Support\Facades\Route;
use Modules\Minutes\Http\Controllers\MeetingController;
use Modules\Minutes\Http\Controllers\SectionController;

Route::get('/minutes', [MeetingController::class, 'index'])->name('minutes.index');
Route::get('/minutes/new', [MeetingController::class, 'create'])->name('minutes.create');
Route::post('/minutes', [MeetingController::class, 'store'])->name('minutes.store');
Route::get('/minutes/{meeting}', [MeetingController::class, 'show'])->name('minutes.show');
Route::get('/minutes/{meeting}/status', [MeetingController::class, 'status'])->name('minutes.status');
Route::post('/minutes/{meeting}/retry', [MeetingController::class, 'retry'])->name('minutes.retry');
Route::delete('/minutes/{meeting}', [MeetingController::class, 'destroy'])->name('minutes.destroy');

Route::put('/minutes/{meeting}/sections/{section}', [SectionController::class, 'update'])->name('minutes.sections.update');
Route::post('/minutes/{meeting}/sections/{section}/regenerate', [SectionController::class, 'regenerate'])->name('minutes.sections.regenerate');
Route::get('/minutes/{meeting}/proposal', [SectionController::class, 'proposal'])->name('minutes.proposal');
Route::post('/minutes/{meeting}/proposal/accept', [SectionController::class, 'accept'])->name('minutes.proposal.accept');
Route::post('/minutes/{meeting}/proposal/discard', [SectionController::class, 'discard'])->name('minutes.proposal.discard');
