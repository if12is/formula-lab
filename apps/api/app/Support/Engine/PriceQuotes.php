<?php

namespace App\Support\Engine;

/**
 * A material's price quotes (MaterialOptionService::option()['prices']) as the engine reads them: candidates for `resolveValue`, each
 * with the dated exchange rate of its quote day. One shape for the cost report, the tiers screen and the dashboard, so a price is
 * the same price everywhere. Nothing is converted or chosen here: that is the engine's.
 */
final class PriceQuotes
{
    /**
     * @param  list<array<string, mixed>>  $prices
     * @return list<array<string, mixed>>
     */
    public static function candidates(array $prices): array
    {
        return array_map(fn (array $price): array => [
            'value' => $price['amount'],
            'unit' => $price['currency'],
            'sourceId' => $price['source_id'],
            'confidence' => $price['confidence'],
            'validFrom' => $price['quoted_on'],
        ], $prices);
    }

    /**
     * A rate chosen for one quote day applies to that day's price only (engine FxRate.forDate).
     *
     * @param  list<array<string, mixed>>  $prices
     * @return list<array<string, mixed>>
     */
    public static function fxRates(array $prices): array
    {
        $rates = [];
        foreach ($prices as $price) {
            if ($price['fx'] !== null) {
                $rates[] = [
                    'from' => $price['currency'],
                    'to' => 'EGP',
                    'rate' => $price['fx']['rate'],
                    'date' => $price['fx']['rate_date'],
                    'sourceId' => $price['fx']['source_id'],
                    'source' => $price['fx']['source_title'],
                    'confidence' => $price['fx']['confidence'],
                    'forDate' => $price['quoted_on'],
                ];
            }
        }

        return $rates;
    }
}
