<?php

namespace App\Services\Currency;

use App\Support\Currencies;

/** What every page needs to show a second figure in the visitor's display currency (shared as the `currency` prop). */
final class DisplayCurrencyService
{
    public function __construct(
        private ExchangeRateLookupService $lookup,
    ) {}

    /**
     * `rate` is the newest rate not in the future (null for EGP, or when none exists yet: the figure then reads "—").
     *
     * @return array{base: string, display: string, options: list<array{value: string, label: string, short: string, symbol: string}>, rate: array<string, string>|null}
     */
    public function props(string $display): array
    {
        return [
            'base' => Currencies::base(),
            'display' => $display,
            'options' => Currencies::options(),
            'rate' => Currencies::isBase($display) ? null : $this->lookup->latest($display),
        ];
    }
}
