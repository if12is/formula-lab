<?php

namespace App\Exceptions\Domain;

/** Thrown when the manufacturing steps of a formula version would break the rules (Q-10). */
class FormulaProcessException extends BusinessRuleViolationException
{
    public static function versionNotDraft(int $version): self
    {
        return new self(__('exceptions.process_version_not_draft', ['version' => $version]));
    }

    public static function unknownLine(int $step, string $lineNo): self
    {
        return new self(__('exceptions.process_unknown_line', ['step' => $step, 'line' => $lineNo]));
    }

    public static function addNeedsLine(int $step): self
    {
        return new self(__('exceptions.process_add_needs_line', ['step' => $step]));
    }

    public static function limitNeedsSource(int $step, int $check): self
    {
        return new self(__('exceptions.process_limit_needs_source', ['step' => $step, 'check' => $check]));
    }

    public static function unitNotAllowed(int $step, string $unit, string $property): self
    {
        return new self(__('exceptions.process_unit_not_allowed', ['step' => $step, 'unit' => $unit, 'property' => $property]));
    }
}
