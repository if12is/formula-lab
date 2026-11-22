<?php

use App\Http\Controllers\Family\FamilyController;
use App\Http\Controllers\Template\TemplateController;
use Illuminate\Support\Facades\Route;

/*
| The product family registry and the formula template library (phase K).
| Loaded from routes/web.php, so it shares the web middleware group. Both are addressed by their code.
*/

Route::prefix('families')->name('families.')->group(function (): void {
    Route::get('/', [FamilyController::class, 'index'])->name('index');
    Route::get('{family:code}', [FamilyController::class, 'show'])->name('show');
});

Route::prefix('templates')->name('templates.')->group(function (): void {
    Route::get('/', [TemplateController::class, 'index'])->name('index');
    Route::get('{template:code}', [TemplateController::class, 'show'])->name('show');
    Route::post('{template:code}/use', [TemplateController::class, 'use'])->name('use');
});
