<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What an ingredient does in a skincare or emulsion formula (M-02). A material may carry several. */
enum FunctionalClass: string
{
    use HasOptions;

    case Humectant = 'humectant';
    case Emollient = 'emollient';
    case Occlusive = 'occlusive';
    case Emulsifier = 'emulsifier';
    case Thickener = 'thickener';
    case Preservative = 'preservative';
    case Active = 'active';
    case Fragrance = 'fragrance';
}
