<?php

namespace App\Services\Currency;

use App\Enums\Confidence;
use App\Enums\SourceKind;
use App\Http\Filters\ListQuery;
use App\Models\ExchangeRate;
use App\Models\Source;
use App\Support\Currencies;
use Illuminate\Http\Request;

/** Read side of the exchange rates screen: the filtered list, the latest rate of each currency and the form options. */
final class ExchangeRateListService
{
    public function __construct(
        private ExchangeRateLookupService $lookup,
    ) {}

    /**
     * The whole `list` prop for /settings/exchange-rates.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request): array
    {
        $currencies = array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], Currencies::options(false));

        return ListQuery::for(ExchangeRate::query()->with('source'), $request)
            ->search(['source.title', 'from_currency'], 'exchange_rates.search_placeholder')
            ->select('currency', $currencies, 'from_currency', 'exchange_rates.filters.currency')
            ->dateRange('date', 'rate_date', 'exchange_rates.filters.date')
            ->select('source', $this->sourceOptions(), 'source_id', 'exchange_rates.filters.source')
            ->select('confidence', Confidence::class, labelKey: 'exchange_rates.filters.confidence')
            ->sortable(['date' => 'rate_date', 'currency' => 'from_currency', 'rate'], default: 'date', direction: 'desc', labelPrefix: 'exchange_rates.sorts')
            ->perPage(config('currencies.list_per_page'), [10, 25, 50, 100])
            ->paginate(fn (ExchangeRate $rate): array => [
                'id' => $rate->getKey(),
                'currency' => $rate->from_currency,
                'rate' => $rate->rate,
                'rate_date' => $rate->rate_date->toDateString(),
                'source_id' => $rate->source_id,
                'source_title' => $rate->source->title,
                'confidence' => $rate->confidence->value,
            ]);
    }

    /**
     * The newest rate (not in the future) of every currency that needs one, null where there is none yet.
     *
     * @return list<array{currency: string, rate: array<string, string>|null}>
     */
    public function latestByCurrency(): array
    {
        return array_map(
            fn (string $code): array => ['currency' => $code, 'rate' => $this->lookup->latest($code)],
            Currencies::foreignCodes(),
        );
    }

    /** @return array<string, mixed> The options and addresses the add/edit modal needs. */
    public function formProps(): array
    {
        return [
            'currencyOptions' => Currencies::options(false),
            'confidenceOptions' => Confidence::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'urls' => [
                'sources' => route('sources.search', [], false),
                'sourceStore' => route('sources.store', [], false),
                'store' => route('settings.exchange-rates.store', [], false),
                'lookup' => route('settings.exchange-rates.lookup', [], false),
            ],
        ];
    }

    /** @return list<array{value: string, label: string}> Sources that back at least one rate. */
    private function sourceOptions(): array
    {
        return Source::query()
            ->whereIn('id', ExchangeRate::query()->select('source_id'))
            ->orderBy('title')
            ->get()
            ->map(fn (Source $source): array => ['value' => $source->getKey(), 'label' => $source->title])
            ->all();
    }
}
