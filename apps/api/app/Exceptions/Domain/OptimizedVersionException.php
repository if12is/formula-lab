<?php

namespace App\Exceptions\Domain;

/** Thrown when an optimizer answer cannot be saved as a draft version (J-09). */
class OptimizedVersionException extends BusinessRuleViolationException
{
    public static function notOptimal(string $status): self
    {
        return new self(__('exceptions.optimized_version_not_optimal', ['status' => __('tiers.result.status.'.$status)]));
    }
}
