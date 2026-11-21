<?php

namespace App\Services\Currency;

use App\Actions\Currency\CreateExchangeRateAction;
use App\Actions\Currency\UpdateExchangeRateAction;
use App\Exceptions\Domain\ExchangeRateException;
use App\Models\ExchangeRate;
use App\Services\Currency\Providers\ExchangeRateProvider;
use App\Support\Currencies;
use App\Support\DecimalNormalizer;
use Illuminate\Support\Facades\DB;

/** The single write path of exchange rates (a rate is never written anywhere else). */
final class ExchangeRateService
{
    public function __construct(
        private CreateExchangeRateAction $createRate,
        private UpdateExchangeRateAction $updateRate,
    ) {}

    /**
     * @param  array{from_currency: string, rate: string, rate_date: string, source_id: string, confidence: string}  $data
     *
     * @throws ExchangeRateException When the currency is the base or unsupported, or the rate already exists.
     */
    public function record(array $data, ?int $userId = null): ExchangeRate
    {
        $data = $this->checked($data);
        $this->assertFree($data);

        return ($this->createRate)($data, $userId);
    }

    /**
     * @param  array{from_currency: string, rate: string, rate_date: string, source_id: string, confidence: string}  $data
     *
     * @throws ExchangeRateException When the currency is the base or unsupported, or another rate already has that key.
     */
    public function update(ExchangeRate $rate, array $data): ExchangeRate
    {
        $data = $this->checked($data);
        $this->assertFree($data, $rate);

        return ($this->updateRate)($rate, $data);
    }

    /**
     * Stores the rates a provider supplies for a day; a rate that is already stored is left as it is.
     *
     * @return list<ExchangeRate> The rates that were newly stored.
     */
    public function importFrom(ExchangeRateProvider $provider, string $date, ?int $userId = null): array
    {
        return DB::transaction(function () use ($provider, $date, $userId): array {
            $stored = [];

            foreach ($provider->fetch($date) as $quote) {
                try {
                    $stored[] = $this->record($quote->toArray(), $userId);
                } catch (ExchangeRateException $exception) {
                    if (! $this->exists($quote->toArray())) {
                        throw $exception;
                    }
                }
            }

            return $stored;
        });
    }

    /**
     * @param  array{from_currency: string, rate: string, rate_date: string, source_id: string, confidence: string}  $data
     * @return array{from_currency: string, rate: string, rate_date: string, source_id: string, confidence: string}
     */
    private function checked(array $data): array
    {
        if (Currencies::isBase($data['from_currency'])) {
            throw ExchangeRateException::baseCurrency($data['from_currency']);
        }

        if (! Currencies::isSupported($data['from_currency'])) {
            throw ExchangeRateException::unsupported($data['from_currency']);
        }

        return [...$data, 'rate' => DecimalNormalizer::normalize($data['rate'])];
    }

    /**
     * @param  array{from_currency: string, rate_date: string, source_id: string}  $data
     */
    private function assertFree(array $data, ?ExchangeRate $except = null): void
    {
        if ($this->exists($data, $except)) {
            throw ExchangeRateException::duplicate($data['from_currency'], $data['rate_date']);
        }
    }

    /**
     * @param  array{from_currency: string, rate_date: string, source_id: string}  $data
     */
    private function exists(array $data, ?ExchangeRate $except = null): bool
    {
        return ExchangeRate::query()
            ->where('from_currency', $data['from_currency'])
            ->where('to_currency', Currencies::base())
            ->whereDate('rate_date', $data['rate_date'])
            ->where('source_id', $data['source_id'])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }
}
