<?php

namespace App\Services\Currency\Providers;

/** Rates are typed in on the exchange rates screen, so there is nothing to fetch. */
final class ManualExchangeRateProvider implements ExchangeRateProvider
{
    public function key(): string
    {
        return 'manual';
    }

    public function fetch(string $date): array
    {
        return [];
    }
}
