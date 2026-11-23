<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Where a formula version that was not typed in the editor came from (J-09). */
enum FormulaVersionOrigin: string
{
    use HasOptions;

    case Optimizer = 'optimizer';
}
