<?php

namespace App\Exceptions\Domain;

/** Thrown when a product's tax setting, or the tax sent with it to Sanad, would break the tax rules. */
class InvalidTaxSettingException extends BusinessRuleViolationException
{
    public static function rateNotAllowed(string $rate): self
    {
        return new self(__('exceptions.tax_rate_not_allowed', ['rate' => $rate]));
    }

    public static function rateRequired(): self
    {
        return new self(__('exceptions.tax_rate_required'));
    }

    public static function settingMissing(string $formula): self
    {
        return new self(__('exceptions.tax_setting_missing', ['formula' => $formula]));
    }

    public static function regimeMissing(string $rate, string $asOf): self
    {
        return new self(__('exceptions.tax_regime_missing', ['rate' => $rate, 'date' => $asOf]));
    }
}
