<?php

namespace App\Enums\Production;

use App\Enums\Concerns\HasOptions;

enum DeviationKind: string
{
    use HasOptions;

    case Weight = 'weight';
    case Process = 'process';
    case Check = 'check';
    case Waste = 'waste';
    case Alkali = 'alkali';
    case Other = 'other';
}
