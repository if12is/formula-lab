<?php

namespace App\Actions\Formula;

use App\Enums\TaxMode;
use App\Exceptions\Domain\InvalidTaxSettingException;
use App\Models\Formula;
use App\Support\DecimalNormalizer;

/**
 * Sets the tax of a formula's product: switched off (no tax line), or a rate. The rate must be one the owner allowed
 * (config/tax.php); a disabled product keeps no rate, so a stale one can never leak into a price.
 */
final class UpdateFormulaTaxAction
{
    /** @throws InvalidTaxSettingException When a rate is missing, or is not one of the allowed rates. */
    public function __invoke(Formula $formula, TaxMode $mode, ?string $ratePct = null): Formula
    {
        if ($mode === TaxMode::Disabled) {
            $formula->update(['tax_mode' => TaxMode::Disabled, 'tax_rate_pct' => null]);

            return $formula;
        }

        if ($ratePct === null || ! DecimalNormalizer::isValid($ratePct)) {
            throw InvalidTaxSettingException::rateRequired();
        }

        $rate = DecimalNormalizer::normalize($ratePct);
        $allowed = array_map(fn (string $allowed): string => DecimalNormalizer::normalize($allowed), config('tax.allowed_rates'));

        if (! in_array($rate, $allowed, true)) {
            throw InvalidTaxSettingException::rateNotAllowed($rate);
        }

        $formula->update(['tax_mode' => TaxMode::Rate, 'tax_rate_pct' => $rate]);

        return $formula;
    }
}
