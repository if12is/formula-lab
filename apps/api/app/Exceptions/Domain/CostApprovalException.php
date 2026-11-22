<?php

namespace App\Exceptions\Domain;

/** Thrown when a product's cost cannot be approved yet: the approval would rest on something missing or unapproved. */
class CostApprovalException extends BusinessRuleViolationException
{
    public static function versionNotApproved(string $formula): self
    {
        return new self(__('exceptions.cost_version_not_approved', ['formula' => $formula]));
    }

    public static function unitCostMissing(string $formula): self
    {
        return new self(__('exceptions.cost_unit_cost_missing', ['formula' => $formula]));
    }

    public static function unitCostIsEstimate(string $formula): self
    {
        return new self(__('exceptions.cost_unit_cost_is_estimate', ['formula' => $formula]));
    }

    /** @param  list<string>  $gaps */
    public static function reportIncomplete(array $gaps): self
    {
        return new self(__('exceptions.cost_report_incomplete', ['gaps' => implode('; ', $gaps)]));
    }
}
