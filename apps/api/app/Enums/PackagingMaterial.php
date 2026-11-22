<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What a packaging component is made of. */
enum PackagingMaterial: string
{
    use HasOptions;

    case Pet = 'pet';
    case Hdpe = 'hdpe';
    case Glass = 'glass';
    case Aluminium = 'aluminium';
    case Carton = 'carton';
}
