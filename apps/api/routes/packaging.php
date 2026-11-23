<?php

use App\Http\Controllers\Packaging\PackagingCompatibilityController;
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

    /* Declared neck finish pairs (H-02): what may be closed with what, and the BOM check built on them. */
    Route::prefix('compatibility')->name('compatibility.')->group(function (): void {
        Route::get('/', [PackagingCompatibilityController::class, 'index'])->name('index');
        Route::post('/', [PackagingCompatibilityController::class, 'store'])->name('store');
        Route::post('check', [PackagingCompatibilityController::class, 'check'])->name('check');
        Route::get('necks/{side}', [PackagingCompatibilityController::class, 'necks'])->name('necks');
        Route::delete('{pair}', [PackagingCompatibilityController::class, 'destroy'])->whereUuid('pair')->name('destroy');
    });
    Route::put('{item}', [PackagingItemController::class, 'update'])->whereUuid('item')->name('update');
});
