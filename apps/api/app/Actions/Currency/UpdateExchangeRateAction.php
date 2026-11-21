<?php

namespace App\Actions\Currency;

use App\Models\ExchangeRate;

/** Updates a rate; the audit trail records the before and after. */
final class UpdateExchangeRateAction
{
    /**
     * @param  array{from_currency: string, rate: string, rate_date: string, source_id: string, confidence: string}  $data
     */
    public function __invoke(ExchangeRate $rate, array $data): ExchangeRate
    {
        $rate->update($data);

        return $rate;
    }
}
