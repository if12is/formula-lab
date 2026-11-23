<?php

use App\Http\Controllers\Pricing\PriceLadderController;
use Illuminate\Support\Facades\Route;

/*
| The price ladder (S-13): cost to shelf and shelf to maximum cost of a product, and the dollar's effect.
| Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::prefix('price-ladder')->name('price-ladder.')->group(function (): void {
    Route::get('/', [PriceLadderController::class, 'index'])->name('index');
    Route::post('calculate', [PriceLadderController::class, 'calculate'])->name('calculate');
});
