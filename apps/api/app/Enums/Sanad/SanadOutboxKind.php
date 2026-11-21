<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Type of a Formula Lab outbox item (contract 3.1). finished_good carries any product upsert body. */
enum SanadOutboxKind: string
{
    use HasOptions;

    case ProductionOrder = 'production_order';
    case FinishedGood = 'finished_good';
    case WholesalePrices = 'wholesale_prices';
}
