<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** The comparison of a rule, exactly the operators of the engine's rules DSL (packages/engine/src/compliance/dsl.ts). */
enum RuleOperator: string
{
    use HasOptions;

    case LessOrEqual = '<=';
    case Less = '<';
    case GreaterOrEqual = '>=';
    case Greater = '>';
    case Equal = '==';
    case Forbidden = 'forbidden';
    case Required = 'required';

    /** `forbidden` and `required` compare with nothing, so they carry no limit. */
    public function takesLimit(): bool
    {
        return $this !== self::Forbidden && $this !== self::Required;
    }
}
