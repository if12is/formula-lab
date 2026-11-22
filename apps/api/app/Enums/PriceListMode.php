<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** How the price of one price list line is decided: a margin rule from config.pricing, or an explicit sourced override. */
enum PriceListMode: string
{
    use HasOptions;

    case Rule = 'rule';
    case Override = 'override';
}
