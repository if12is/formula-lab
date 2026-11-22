<?php

namespace App\Exceptions\Domain;

use App\Support\Families\EngineReasonText;

/** Thrown when the product family registry or the template library fails the engine's validation, so nothing is loaded. */
class InvalidFamilyDataException extends BusinessRuleViolationException
{
    /** @param  list<array{property: string, reason: string}>  $missing */
    public static function rejectedByEngine(array $missing): self
    {
        $lines = array_map(fn (array $gap): string => "{$gap['property']}: ".EngineReasonText::of($gap['reason']), $missing);

        return new self(__('exceptions.family_data_invalid', ['details' => implode('; ', $lines)]));
    }
}
