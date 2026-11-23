<?php

use App\Http\Controllers\Packaging\PackagingBomController;
use App\Http\Controllers\Packaging\PackagingBomImageController;
use App\Http\Controllers\Packaging\PackagingBomLineController;
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
    /* The packaging BOM of each SKU (S-12): its lines, pack image and the engine's cost preview. */
    Route::prefix('boms')->name('boms.')->group(function (): void {
        Route::get('/', [PackagingBomController::class, 'index'])->name('index');
        Route::post('/', [PackagingBomController::class, 'store'])->name('store');
        Route::prefix('{bom}')->whereUuid('bom')->group(function (): void {
            Route::get('/', [PackagingBomController::class, 'show'])->name('show');
            Route::put('/', [PackagingBomController::class, 'update'])->name('update');
            Route::delete('/', [PackagingBomController::class, 'destroy'])->name('destroy');
            Route::get('cost', [PackagingBomController::class, 'cost'])->name('cost');
            Route::get('image', [PackagingBomImageController::class, 'show'])->name('image.show');
            Route::post('image', [PackagingBomImageController::class, 'store'])->name('image.store');
            Route::delete('image', [PackagingBomImageController::class, 'destroy'])->name('image.destroy');
            Route::post('lines', [PackagingBomLineController::class, 'store'])->name('lines.store');
            Route::put('lines/{line}', [PackagingBomLineController::class, 'update'])->whereUuid('line')->scopeBindings()->name('lines.update');
            Route::delete('lines/{line}', [PackagingBomLineController::class, 'destroy'])->whereUuid('line')->scopeBindings()->name('lines.destroy');
        });
    });
    Route::put('{item}', [PackagingItemController::class, 'update'])->whereUuid('item')->name('update');
});
