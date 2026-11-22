<?php

namespace App\Exceptions\Domain;

/** Thrown when the product family registry or the template library fails the engine's validation, so nothing is loaded. */
class InvalidFamilyDataException extends BusinessRuleViolationException
{
    /** @param  list<array{property: string, reason: string}>  $missing */
    public static function rejectedByEngine(array $missing): self
    {
        $lines = array_map(fn (array $gap): string => "{$gap['property']}: ".__('families.reasons.'.self::bare($gap['reason'])), $missing);

        return new self(__('exceptions.family_data_invalid', ['details' => implode('; ', $lines)]));
    }

    /** The engine's `families.<name>` key without its group. */
    private static function bare(string $reason): string
    {
        return str_starts_with($reason, 'families.') ? substr($reason, strlen('families.')) : $reason;
    }
}
