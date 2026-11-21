<?php

namespace App\Services\Material;

use App\Enums\SourceKind;
use App\Models\Price;
use App\Models\TradeMaterial;
use App\Services\Currency\ExchangeRateLookupService;
use Illuminate\Support\Collection;

/**
 * Trade materials as typeahead options, with their price quotes: the formula editor hands the quotes to the
 * engine, which picks the one to cost with (best confidence, then latest date). A price in another currency carries the
 * exchange rate of its quote date (ExchangeRateLookupService), which the engine converts with.
 */
final class MaterialOptionService
{
    public function __construct(
        private ExchangeRateLookupService $exchangeRateLookup,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $term): array
    {
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        $materials = TradeMaterial::query()
            ->with('substance')
            ->when($term !== '', fn ($query) => $query->where(fn ($group) => $group
                ->where('trade_name', 'ilike', $pattern)
                ->orWhere('grade', 'ilike', $pattern)
                ->orWhereHas('substance', fn ($substance) => $substance
                    ->where('inci', 'ilike', $pattern)
                    ->orWhere('cas', 'ilike', $pattern))))
            ->orderBy('trade_name')
            ->limit(config('formula.search_limit'))
            ->get();

        return array_values($this->forMaterials($materials));
    }

    /**
     * @param  Collection<int, TradeMaterial>  $materials
     * @return array<string, array<string, mixed>> Keyed by material id.
     */
    public function forMaterials(Collection $materials): array
    {
        $prices = Price::query()
            ->with('source')
            ->whereIn('trade_material_id', $materials->map(fn (TradeMaterial $material) => $material->getKey())->all())
            ->get()
            ->groupBy('trade_material_id');

        return $materials->mapWithKeys(fn (TradeMaterial $material): array => [
            $material->getKey() => $this->option($material, $prices->get($material->getKey(), collect())),
        ])->all();
    }

    /**
     * @param  Collection<int, Price>  $prices
     * @return array<string, mixed>
     */
    public function option(TradeMaterial $material, Collection $prices): array
    {
        $details = array_filter([$material->grade, $material->function]);
        $rates = $this->exchangeRateLookup->forPrices($prices);

        return [
            'value' => $material->getKey(),
            'label' => $material->trade_name,
            'description' => $details === [] ? null : implode(' · ', $details),
            'status' => $material->status->value,
            'prices' => $prices->map(fn (Price $price): array => [
                'id' => $price->getKey(),
                'amount' => $price->amount,
                'currency' => $price->currency,
                'per_unit' => $price->per_unit,
                'quoted_on' => $price->quoted_on->toDateString(),
                'source_id' => $price->source_id,
                'source_title' => $price->source->kind === SourceKind::UnverifiedEntry->value ? SourceKind::UnverifiedEntry->label() : $price->source->title,
                'confidence' => $price->confidence->value,
                // The rate of the quote date (null for EGP, or when none exists: the engine then reports it missing).
                'fx' => $rates[(string) $price->getKey()],
            ])->values()->all(),
        ];
    }
}
