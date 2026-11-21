<?php

namespace App\Exceptions\Domain;

/** Thrown when an exchange rate would break the rules of the rates table. */
class ExchangeRateException extends BusinessRuleViolationException
{
    public static function duplicate(string $currency, string $date): self
    {
        return new self(__('exceptions.exchange_rate_duplicate', ['currency' => $currency, 'date' => $date]));
    }

    public static function baseCurrency(string $currency): self
    {
        return new self(__('exceptions.exchange_rate_base_currency', ['currency' => $currency]));
    }

    public static function unsupported(string $currency): self
    {
        return new self(__('exceptions.exchange_rate_unsupported_currency', ['currency' => $currency]));
    }
}
