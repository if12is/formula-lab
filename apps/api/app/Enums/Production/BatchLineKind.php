<?php

namespace App\Enums\Production;

use App\Enums\Concerns\HasOptions;

enum BatchLineKind: string
{
    use HasOptions;

    case RawMaterial = 'raw_material';
    case Packaging = 'packaging';
}
