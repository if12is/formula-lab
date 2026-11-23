<?php

use App\Enums\Sanad\SanadAbility;
use App\Http\Controllers\Api\Assistant\AssistantController;
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

// The Claude Haiku formulation assistant (phase P). No sign-in exists yet (U-01), so the guard is the rate limit; the model key stays on the server.
Route::prefix('assistant')->name('assistant.')->middleware('throttle:assistant')->group(function () {
    Route::post('ask', [AssistantController::class, 'ask'])->name('ask');
});
