<?php

use App\Http\Controllers\Formula\FormulaController;
use App\Http\Controllers\Formula\FormulaVersionController;
use App\Http\Controllers\Material\MaterialCompositionController;
use App\Http\Controllers\Material\MaterialController;
use App\Http\Controllers\Material\MaterialPriceController;
use App\Http\Controllers\Material\MaterialPropertyController;
use App\Http\Controllers\Reference\SourceController;
use App\Http\Controllers\Reference\SubstanceController;
use Illuminate\Support\Facades\Route;

/*
| The formulation screens (S-03): the materials library and the formula editor.
| Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::prefix('materials')->name('materials.')->group(function (): void {
    Route::get('/', [MaterialController::class, 'index'])->name('index');
    Route::post('/', [MaterialController::class, 'store'])->name('store');
    Route::get('search', [MaterialController::class, 'search'])->name('search');
    Route::get('functions', [MaterialController::class, 'functions'])->name('functions');
    Route::post('draft', [MaterialController::class, 'storeDraft'])->name('draft.store');

    Route::prefix('{material}')->whereUuid('material')->scopeBindings()->group(function (): void {
        Route::get('/', [MaterialController::class, 'show'])->name('show');
        Route::put('/', [MaterialController::class, 'update'])->name('update');
        Route::post('properties', [MaterialPropertyController::class, 'store'])->name('properties.store');
        Route::delete('properties/{propertyValue}', [MaterialPropertyController::class, 'destroy'])->whereUuid('propertyValue')->name('properties.destroy');
        Route::post('prices', [MaterialPriceController::class, 'store'])->name('prices.store');
        Route::delete('prices/{price}', [MaterialPriceController::class, 'destroy'])->whereUuid('price')->name('prices.destroy');
        Route::post('composition', [MaterialCompositionController::class, 'store'])->name('composition.store');
        Route::put('composition-flags', [MaterialCompositionController::class, 'flags'])->name('composition.flags');
        Route::put('composition/{composition}', [MaterialCompositionController::class, 'update'])->whereUuid('composition')->name('composition.update');
        Route::delete('composition/{composition}', [MaterialCompositionController::class, 'destroy'])->whereUuid('composition')->name('composition.destroy');
    });
});

Route::get('substances/search', [SubstanceController::class, 'search'])->name('substances.search');
Route::post('substances', [SubstanceController::class, 'store'])->name('substances.store');
Route::get('sources/search', [SourceController::class, 'search'])->name('sources.search');
Route::post('sources', [SourceController::class, 'store'])->name('sources.store');

Route::prefix('formulas')->name('formulas.')->group(function (): void {
    Route::get('/', [FormulaController::class, 'index'])->name('index');
    Route::get('create', [FormulaController::class, 'create'])->name('create');
    Route::post('/', [FormulaController::class, 'store'])->name('store');
    Route::get('product-types', [FormulaController::class, 'productTypes'])->name('product-types');

    Route::prefix('{formula}')->whereUuid('formula')->scopeBindings()->group(function (): void {
        Route::get('/', [FormulaController::class, 'show'])->name('show');
        Route::get('compare', [FormulaVersionController::class, 'compare'])->name('compare');
        Route::put('tax', [FormulaController::class, 'updateTax'])->name('tax.update');
        Route::put('ifra-category', [FormulaController::class, 'updateIfraCategory'])->name('ifra-category.update');
        Route::get('versions/{version:version}/edit', [FormulaVersionController::class, 'edit'])->whereNumber('version')->name('versions.edit');
        Route::post('versions/{version:version}', [FormulaVersionController::class, 'store'])->whereNumber('version')->name('versions.store');
        Route::get('versions/{version:version}/label', [FormulaVersionController::class, 'label'])->whereNumber('version')->name('versions.label');
    });
});
