<?php

namespace App\Exceptions\Domain;

/** Thrown when a new formula version would break the versioning rules. */
class InvalidFormulaVersionException extends BusinessRuleViolationException
{
    public static function linesRequired(): self
    {
        return new self(__('exceptions.formula_version_lines_required'));
    }

    public static function reasonRequired(): self
    {
        return new self(__('exceptions.formula_version_reason_required'));
    }

    public static function unknownMaterial(string $materialId): self
    {
        return new self(__('exceptions.formula_version_unknown_material', ['material' => $materialId]));
    }
}
