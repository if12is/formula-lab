<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Type of a Formula Lab outbox item (contract 3.1). product_upsert carries a product upsert body of any item_kind. */
enum SanadOutboxKind: string
{
    use HasOptions;

    case ProductionOrder = 'production_order';
    case ProductUpsert = 'product_upsert';
    case WholesalePrices = 'wholesale_prices';
}
