<?php

namespace App\Services\Currency;

use App\Models\ExchangeRate;
use App\Models\Price;
use App\Support\Currencies;
use Illuminate\Support\Collection;

/**
 * The one place that decides which exchange rate applies. The rule: the rate dated on or before the day asked about, the
 * latest such; on one day a tenant's own rate before a global one, then the better confidence, then the newer entry.
 * No rate on or before the day means no rate (null): nothing is carried backwards, defaulted or invented, and the engine
 * then reports `fx:XXX->EGP` as missing.
 *
 * Prices use their quote date, the display currency uses today. The result is plain data for the screens
 * (props or the lookup endpoint); the browser never picks or converts a rate itself.
 */
final class ExchangeRateLookupService
{
    /**
     * @return array{id: string, currency: string, rate: string, rate_date: string, source_id: string, source_title: string, confidence: string}|null
     */
    public function rateOn(string $currency, string $date, ?string $tenantId = null): ?array
    {
        if (Currencies::isBase($currency) || ! Currencies::isSupported($currency)) {
            return null;
        }

        $rate = ExchangeRate::query()
            ->with('source')
            ->where('from_currency', $currency)
            ->where('to_currency', Currencies::base())
            ->whereDate('rate_date', '<=', $date)
            ->where(fn ($scope) => $scope->whereNull('tenant_id')->when($tenantId !== null, fn ($own) => $own->orWhere('tenant_id', $tenantId)))
            ->orderByDesc('rate_date')
            ->orderByRaw('tenant_id is null')
            ->orderBy('confidence')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->first();

        return $rate === null ? null : $this->shape($rate);
    }

    /**
     * The newest rate that is not in the future (display currency).
     *
     * @return array{id: string, currency: string, rate: string, rate_date: string, source_id: string, source_title: string, confidence: string}|null
     */
    public function latest(string $currency, ?string $tenantId = null): ?array
    {
        return $this->rateOn($currency, now()->toDateString(), $tenantId);
    }

    /**
     * The rate of each price's quote date, keyed by price id; null for a price in the base currency and for a foreign one
     * with no rate on or before its date.
     *
     * @param  Collection<int, Price>  $prices
     * @return array<string, array{id: string, currency: string, rate: string, rate_date: string, source_id: string, source_title: string, confidence: string}|null>
     */
    public function forPrices(Collection $prices, ?string $tenantId = null): array
    {
        $found = [];
        $rates = [];

        foreach ($prices as $price) {
            $key = $price->currency.'|'.$price->quoted_on->toDateString();
            $rates[$key] = array_key_exists($key, $rates) ? $rates[$key] : $this->rateOn($price->currency, $price->quoted_on->toDateString(), $tenantId);
            $found[(string) $price->getKey()] = $rates[$key];
        }

        return $found;
    }

    /**
     * @return array{id: string, currency: string, rate: string, rate_date: string, source_id: string, source_title: string, confidence: string}
     */
    public function shape(ExchangeRate $rate): array
    {
        return [
            'id' => $rate->getKey(),
            'currency' => $rate->from_currency,
            'rate' => $rate->rate,
            'rate_date' => $rate->rate_date->toDateString(),
            'source_id' => $rate->source_id,
            'source_title' => $rate->source->title,
            'confidence' => $rate->confidence->value,
        ];
    }
}
