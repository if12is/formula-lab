<?php

namespace App\Support\DTOs;

/** One rate a provider supplies: `rate` units of the base currency per 1 unit of `fromCurrency` on `rateDate`. */
final readonly class ExchangeRateQuote
{
    public function __construct(
        public string $fromCurrency,
        public string $rate,
        public string $rateDate,
        public string $sourceId,
        public string $confidence,
    ) {}

    /** @return array{from_currency: string, rate: string, rate_date: string, source_id: string, confidence: string} */
    public function toArray(): array
    {
        return [
            'from_currency' => $this->fromCurrency,
            'rate' => $this->rate,
            'rate_date' => $this->rateDate,
            'source_id' => $this->sourceId,
            'confidence' => $this->confidence,
        ];
    }
}
