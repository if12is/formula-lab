<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Entity kinds stored in sanad_refs (data-model.sql). */
enum SanadRefType: string
{
    use HasOptions;

    case Product = 'product';
    case Supplier = 'supplier';
    case Purchase = 'purchase';
    case PurchaseItem = 'purchase_item';
    case Batch = 'batch';
}
