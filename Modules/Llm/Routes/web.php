<?php

use Illuminate\Support\Facades\Route;
use Modules\Llm\Http\Controllers\Admin\GenerationRunController;
use Modules\Llm\Http\Controllers\Admin\LlmSettingsController;
use Modules\Llm\Http\Controllers\Admin\PromptTemplateController;

Route::get('/llm', [LlmSettingsController::class, 'edit'])->name('llm.admin.settings');
Route::put('/llm', [LlmSettingsController::class, 'update'])->name('llm.admin.settings.update');
Route::post('/llm/test', [LlmSettingsController::class, 'testConnection'])->name('llm.admin.settings.test');

Route::get('/prompts', [PromptTemplateController::class, 'index'])->name('llm.admin.prompts');
Route::get('/prompts/{promptTemplate}', [PromptTemplateController::class, 'edit'])->name('llm.admin.prompts.edit');
Route::post('/prompts/{promptTemplate}/versions', [PromptTemplateController::class, 'storeVersion'])->name('llm.admin.prompts.version');

Route::get('/runs', [GenerationRunController::class, 'index'])->name('llm.admin.runs');
