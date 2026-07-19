<?php

use Illuminate\Support\Facades\Route;
use Modules\Minutes\Http\Controllers\MeetingController;

Route::get('/minutes', [MeetingController::class, 'index'])->name('minutes.index');
Route::get('/minutes/new', [MeetingController::class, 'create'])->name('minutes.create');
Route::post('/minutes', [MeetingController::class, 'store'])->name('minutes.store');
Route::get('/minutes/{meeting}', [MeetingController::class, 'show'])->name('minutes.show');
Route::get('/minutes/{meeting}/status', [MeetingController::class, 'status'])->name('minutes.status');
Route::post('/minutes/{meeting}/retry', [MeetingController::class, 'retry'])->name('minutes.retry');
Route::delete('/minutes/{meeting}', [MeetingController::class, 'destroy'])->name('minutes.destroy');
