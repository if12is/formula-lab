<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** The process phases of an emulsion formula (M-01). The same codes the engine's phase validation knows. */
enum FormulaPhase: string
{
    use HasOptions;

    case Water = 'water';
    case Oil = 'oil';
    case CoolDown = 'cool_down';
}
