<?php

use App\Http\Controllers\Tiers\TierController;
use Illuminate\Support\Facades\Route;

/*
| The tiers screen (S-05): economy / mid / premium of a product, the optimizer's answer and its sensitivity.
| Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::prefix('tiers')->name('tiers.')->group(function (): void {
    Route::get('/', [TierController::class, 'index'])->name('index');
    Route::put('profile', [TierController::class, 'saveProfile'])->name('profile.save');
    Route::post('solve', [TierController::class, 'solve'])->name('solve');
});
