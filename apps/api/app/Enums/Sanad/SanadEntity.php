<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Entity of a Sanad push item (contract section 2). */
enum SanadEntity: string
{
    use HasOptions;

    case Product = 'product';
    case Supplier = 'supplier';
    case Customer = 'customer';
    case Purchase = 'purchase';
    case Batch = 'batch';
    case StockMovement = 'stock_movement';
}
