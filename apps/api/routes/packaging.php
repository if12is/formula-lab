<?php

use App\Http\Controllers\Packaging\PackagingItemController;
use Illuminate\Support\Facades\Route;

/*
| The packaging component library (H-01): bottles, closures, labels and cartons with their spec.
| Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::prefix('packaging')->name('packaging.')->group(function (): void {
    Route::get('/', [PackagingItemController::class, 'index'])->name('index');
    Route::post('/', [PackagingItemController::class, 'store'])->name('store');
    Route::get('necks', [PackagingItemController::class, 'necks'])->name('necks');
    Route::put('{item}', [PackagingItemController::class, 'update'])->whereUuid('item')->name('update');
});
