<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The ionic character of an emulsifier or surfactant (M-08). It decides the calculation path: only a non-ionic
 * emulsifier follows the HLB mixing rule; a cationic one is sized from the supplier's TDS range and an anionic one
 * from its active matter. The values are the engine's `Ionicity` words.
 */
enum Ionicity: string
{
    use HasOptions;

    case Nonionic = 'nonionic';
    case Anionic = 'anionic';
    case Cationic = 'cationic';
    case Amphoteric = 'amphoteric';
    case Other = 'other';
}
