<?php

use Illuminate\Support\Facades\Route;
use Modules\Backup\Http\Controllers\Admin\BackupController;

Route::get('/backups', [BackupController::class, 'index'])->name('backup.admin.index');
Route::post('/backups/run', [BackupController::class, 'run'])->name('backup.admin.run');
Route::get('/backups/download', [BackupController::class, 'download'])->name('backup.admin.download');
Route::delete('/backups', [BackupController::class, 'destroy'])->name('backup.admin.destroy');
Route::put('/backups/settings', [BackupController::class, 'updateSettings'])->name('backup.admin.settings');
