<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What one BOM quantity is counted against (the engine's PackLevel): a selling unit, a shipping carton or a pallet. */
enum PackLevel: string
{
    use HasOptions;

    case Unit = 'unit';
    case Carton = 'carton';
    case Pallet = 'pallet';
}
