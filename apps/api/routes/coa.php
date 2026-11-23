<?php

use App\Http\Controllers\Coa\CoaExtractionController;
use Illuminate\Support\Facades\Route;

/*
| COA extraction (P-05): the list and upload, and the review screen (confirm, edit or reject each value). Loaded from routes/web.php.
*/

Route::prefix('coa')->name('coa.')->group(function (): void {
    Route::get('/', [CoaExtractionController::class, 'index'])->name('index');
    Route::post('/', [CoaExtractionController::class, 'store'])->name('store');

    Route::prefix('{extraction}')->whereUuid('extraction')->group(function (): void {
        Route::get('/', [CoaExtractionController::class, 'show'])->name('show');
        Route::get('file', [CoaExtractionController::class, 'file'])->name('file');
        Route::post('confirm', [CoaExtractionController::class, 'confirm'])->name('confirm');
        Route::post('reject', [CoaExtractionController::class, 'reject'])->name('reject');
    });
});
