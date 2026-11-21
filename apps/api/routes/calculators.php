<?php

use App\Http\Controllers\Calculator\CalculatorController;
use App\Services\Calculator\CalculatorCatalogService;
use Illuminate\Support\Facades\Route;

/*
| The calculators (S-07, S-14). Loaded from routes/web.php, so it shares the web middleware group.
| Every number on these pages comes from the engine in the browser; the server only serves the pages and the material lookup.
*/

Route::prefix('calculators')->name('calculators.')->group(function (): void {
    Route::get('/', [CalculatorController::class, 'index'])->name('index');
    Route::get('materials', [CalculatorController::class, 'materials'])->name('materials.search');
    Route::get('{calculator}', [CalculatorController::class, 'show'])
        ->whereIn('calculator', (new CalculatorCatalogService)->keys())
        ->name('show');
});
