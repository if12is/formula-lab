<?php

namespace App\Actions\Currency;

use App\Models\ExchangeRate;
use App\Support\Currencies;

/** Inserts one dated, sourced exchange rate into the base currency. */
final class CreateExchangeRateAction
{
    /**
     * @param  array{from_currency: string, rate: string, rate_date: string, source_id: string, confidence: string}  $data
     */
    public function __invoke(array $data, ?int $userId = null): ExchangeRate
    {
        return ExchangeRate::query()->create([
            ...$data,
            'tenant_id' => null,
            'to_currency' => Currencies::base(),
            'created_by' => $userId,
        ]);
    }
}
