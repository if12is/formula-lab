<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What a rule measures. The value is the key of the rule's `target` object in the engine's rules DSL. */
enum RuleTargetType: string
{
    use HasOptions;

    case SubstanceCas = 'substanceCas';
    case Inci = 'inci';
    case Function = 'function';
    case MaterialId = 'materialId';
    case Field = 'field';

    /** Substance, INCI and function targets are concentrations; a material or a product field is not. */
    public function isConcentration(): bool
    {
        return $this !== self::Field;
    }
}
