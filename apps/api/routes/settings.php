<?php

use App\Http\Controllers\Settings\ExchangeRateController;
use Illuminate\Support\Facades\Route;

/*
| The Settings area. Loaded from routes/web.php, so it shares the web middleware group.
| /settings itself stays the ComingSoon placeholder until it gets an overview.
*/

Route::prefix('settings/exchange-rates')->name('settings.exchange-rates.')->group(function (): void {
    Route::get('/', [ExchangeRateController::class, 'index'])->name('index');
    Route::post('/', [ExchangeRateController::class, 'store'])->name('store');
    Route::get('lookup', [ExchangeRateController::class, 'lookup'])->name('lookup');
    Route::put('{rate}', [ExchangeRateController::class, 'update'])->whereUuid('rate')->name('update');
});
