<?php

use App\Http\Controllers\Formula\FormulaProcessController;
use App\Http\Controllers\Production\ProductionBatchController;
use App\Http\Controllers\Production\ProductionDeviationController;
use App\Http\Controllers\Production\ProductionOrderController;
use App\Http\Controllers\Production\ProductionTraceController;
use Illuminate\Support\Facades\Route;

/*
| Production (phase Q): orders made from approved formula versions, the operator's weighing sheet, batches with their deviations and
| the lot trace. The stock side is Sanad's: nothing here moves stock. Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::get('formulas/{formula}/versions/{version:version}/process', [FormulaProcessController::class, 'show'])->whereUuid('formula')->whereNumber('version')->scopeBindings()->name('formulas.versions.process');
Route::put('formulas/{formula}/versions/{version:version}/process', [FormulaProcessController::class, 'update'])->whereUuid('formula')->whereNumber('version')->scopeBindings()->name('formulas.versions.process.update');

Route::redirect('/production', '/production/orders')->name('production.home');

Route::prefix('production')->name('production.')->group(function (): void {
    Route::get('formulas/options', [ProductionOrderController::class, 'formulaOptions'])->name('formulas.options');
    Route::get('versions/search', [ProductionOrderController::class, 'versionSearch'])->name('versions.search');
    Route::get('packaging/search', [ProductionOrderController::class, 'packagingSearch'])->name('packaging.search');
    Route::get('trace', [ProductionTraceController::class, 'index'])->name('trace');

    Route::prefix('orders')->name('orders.')->group(function (): void {
        Route::get('/', [ProductionOrderController::class, 'index'])->name('index');
        Route::get('create', [ProductionOrderController::class, 'create'])->name('create');
        Route::post('/', [ProductionOrderController::class, 'store'])->name('store');

        Route::prefix('{order}')->whereUuid('order')->group(function (): void {
            Route::get('/', [ProductionOrderController::class, 'show'])->name('show');
            Route::get('weighing', [ProductionOrderController::class, 'weighing'])->name('weighing');
            Route::post('approve', [ProductionOrderController::class, 'approve'])->name('approve');
            Route::post('send', [ProductionOrderController::class, 'send'])->name('send');
            Route::post('batch', [ProductionOrderController::class, 'startBatch'])->name('batch.store');
        });
    });

    Route::prefix('batches/{batch}')->whereUuid('batch')->name('batches.')->group(function (): void {
        Route::get('/', [ProductionBatchController::class, 'show'])->name('show');
        Route::put('lines/{line}', [ProductionBatchController::class, 'weigh'])->whereUuid('line')->scopeBindings()->name('lines.weigh');
        Route::post('checks', [ProductionBatchController::class, 'storeCheck'])->name('checks.store');
        Route::post('deviations', [ProductionBatchController::class, 'storeDeviation'])->name('deviations.store');
        Route::put('results', [ProductionBatchController::class, 'updateResults'])->name('results.update');
        Route::post('complete', [ProductionBatchController::class, 'complete'])->name('complete');
    });

    Route::post('deviations/{deviation}/decide', [ProductionDeviationController::class, 'decide'])->whereUuid('deviation')->name('deviations.decide');
});
