<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** The tax choice on a product: no tax at all (no tax line), or a rate taken from config/tax.php. */
enum TaxMode: string
{
    use HasOptions;

    case Disabled = 'disabled';
    case Rate = 'rate';
}
