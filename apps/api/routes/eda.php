<?php

use App\Http\Controllers\Eda\EdaChecklistController;
use Illuminate\Support\Facades\Route;

/*
| The EDA notification file assistant (N-08): the products EDA regulates and each one's checklist.
| Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::prefix('eda')->name('eda.')->group(function (): void {
    Route::get('/', [EdaChecklistController::class, 'index'])->name('index');

    Route::prefix('{formula}')->whereUuid('formula')->scopeBindings()->group(function (): void {
        Route::get('/', [EdaChecklistController::class, 'show'])->name('show');
        Route::post('items/{itemCode}', [EdaChecklistController::class, 'save'])->where('itemCode', '[a-z][a-z0-9_]*')->name('items.save');
        Route::get('items/{itemCode}/file', [EdaChecklistController::class, 'file'])->where('itemCode', '[a-z][a-z0-9_]*')->name('items.file');
    });
});
