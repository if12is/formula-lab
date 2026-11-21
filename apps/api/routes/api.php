<?php

use App\Enums\Sanad\SanadAbility;
use App\Http\Controllers\Api\Sanad\ChangesController;
use App\Http\Controllers\Api\Sanad\OutboxController;
use Illuminate\Support\Facades\Route;

// Sanad-initiated link (contract sections 2 and 3). The `api` prefix comes from bootstrap/app.php.
Route::prefix('sanad/v1')->name('sanad.')->middleware('throttle:sanad')->group(function () {
    Route::post('changes', ChangesController::class)
        ->middleware(['sanad.device:'.SanadAbility::ChangesWrite->value, 'idempotent'])
        ->name('changes');

    Route::get('outbox', [OutboxController::class, 'index'])
        ->middleware('sanad.device:'.SanadAbility::OutboxRead->value)
        ->name('outbox.index');

    Route::post('outbox/ack', [OutboxController::class, 'ack'])
        ->middleware(['sanad.device:'.SanadAbility::OutboxAck->value, 'idempotent'])
        ->name('outbox.ack');
});
