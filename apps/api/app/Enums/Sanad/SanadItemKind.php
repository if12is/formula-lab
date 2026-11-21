<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Sanad Product.item_kind (contract section 4.2). */
enum SanadItemKind: string
{
    use HasOptions;

    case Merchandise = 'merchandise';
    case RawMaterial = 'raw_material';
    case Packaging = 'packaging';
    case FinishedGood = 'finished_good';
}
