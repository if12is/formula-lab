<?php

use App\Http\Controllers\Lab\LabDensityController;
use App\Http\Controllers\Lab\LabProtocolController;
use App\Http\Controllers\Lab\LabTrialController;
use Illuminate\Support\Facades\Route;

/*
| The lab loop (phase O): trials with their measurements and the test protocol templates.
| Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::redirect('/lab', '/lab/trials')->name('lab.home');

Route::prefix('lab')->name('lab.')->group(function (): void {
    Route::get('formulas/options', [LabTrialController::class, 'formulaOptions'])->name('formulas.options');
    Route::get('formula-versions/search', [LabTrialController::class, 'versionSearch'])->name('formula-versions.search');
    Route::get('formula-versions/{version}/density', [LabDensityController::class, 'show'])->whereUuid('version')->name('formula-versions.density');

    Route::prefix('trials')->name('trials.')->group(function (): void {
        Route::get('/', [LabTrialController::class, 'index'])->name('index');
        Route::get('create', [LabTrialController::class, 'create'])->name('create');
        Route::post('/', [LabTrialController::class, 'store'])->name('store');

        Route::prefix('{trial}')->whereUuid('trial')->group(function (): void {
            Route::get('/', [LabTrialController::class, 'show'])->name('show');
            Route::post('measurements', [LabTrialController::class, 'storeMeasurement'])->name('measurements.store');
            Route::post('complete', [LabTrialController::class, 'complete'])->name('complete');
        });
    });

    Route::prefix('protocols')->name('protocols.')->group(function (): void {
        Route::get('/', [LabProtocolController::class, 'index'])->name('index');
        Route::get('{protocol}', [LabProtocolController::class, 'show'])->whereUuid('protocol')->name('show');
    });
});
