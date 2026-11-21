<?php

use App\Http\Controllers\Compliance\RuleController;
use App\Http\Controllers\Compliance\RuleSetController;
use Illuminate\Support\Facades\Route;

/*
| The compliance screens (S-06): rule sets and their rules. Loaded from routes/web.php, so it shares the web middleware group.
| The compliance panel of the formula editor and the label page are formula routes (routes/formulation.php).
*/

Route::prefix('compliance')->name('compliance.')->group(function (): void {
    Route::get('/', [RuleSetController::class, 'index'])->name('index');
    Route::post('/', [RuleSetController::class, 'store'])->name('store');
    Route::get('product-types', [RuleSetController::class, 'productTypes'])->name('product-types');

    Route::prefix('{ruleSet}')->whereUuid('ruleSet')->scopeBindings()->group(function (): void {
        Route::get('/', [RuleSetController::class, 'show'])->name('show');
        Route::put('/', [RuleSetController::class, 'update'])->name('update');
        Route::delete('/', [RuleSetController::class, 'destroy'])->name('destroy');
        Route::post('publish', [RuleSetController::class, 'publish'])->name('publish');
        Route::post('versions', [RuleSetController::class, 'newVersion'])->name('versions.store');
        Route::post('rules', [RuleController::class, 'store'])->name('rules.store');
        Route::put('rules/{rule}', [RuleController::class, 'update'])->whereUuid('rule')->name('rules.update');
        Route::delete('rules/{rule}', [RuleController::class, 'destroy'])->whereUuid('rule')->name('rules.destroy');
    });
});
