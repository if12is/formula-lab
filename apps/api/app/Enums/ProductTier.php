<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ProductTier: string
{
    use HasOptions;

    case Economy = 'economy';
    case Mid = 'mid';
    case Premium = 'premium';
}
