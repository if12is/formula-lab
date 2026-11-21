<?php

namespace App\Exceptions\Domain;

/** Thrown when a lab trial or measurement would break the lab rules. */
class LabTrialException extends BusinessRuleViolationException
{
    public static function closed(string $code): self
    {
        return new self(__('exceptions.lab_trial_closed', ['trial' => $code]));
    }

    public static function noMeasurements(string $code): self
    {
        return new self(__('exceptions.lab_trial_no_measurements', ['trial' => $code]));
    }

    public static function unitNotAllowed(string $unit, string $property): self
    {
        return new self(__('exceptions.lab_unit_not_allowed', ['unit' => $unit, 'property' => $property]));
    }

    public static function valueNotDecimal(string $property): self
    {
        return new self(__('exceptions.lab_value_not_decimal', ['property' => $property]));
    }
}
