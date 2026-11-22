<?php

namespace App\Services\Material;

use App\Enums\SourceKind;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use App\Services\Currency\ExchangeRateLookupService;
use Illuminate\Support\Collection;

/**
 * Trade materials as typeahead options, with their price quotes: the formula editor hands the quotes to the
 * engine, which picks the one to cost with (best confidence, then latest date). A price in another currency carries the
 * exchange rate of its quote date (ExchangeRateLookupService), which the engine converts with. They also carry what the
 * compliance engine needs: the material's composition (MaterialCompositionService::enginePayloads) and, for a fragrance
 * oil, the limits of its IFRA certificate (property `ifra_limit` per category).
 */
final class MaterialOptionService
{
    public function __construct(
        private ExchangeRateLookupService $exchangeRateLookup,
        private MaterialCompositionService $compositionService,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $term): array
    {
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        $materials = TradeMaterial::query()
            ->ingredients()
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
        $compositions = $this->compositionService->enginePayloads($materials);
        $ifraLimits = $this->ifraLimits($materials);

        return $materials->mapWithKeys(fn (TradeMaterial $material): array => [
            $material->getKey() => $this->option($material, $prices->get($material->getKey(), collect()), $compositions[$material->getKey()] ?? null, $ifraLimits[$material->getKey()] ?? []),
        ])->all();
    }

    /**
     * @param  Collection<int, Price>  $prices
     * @param  array<string, mixed>|null  $composition  The engine's composition of the material, null when it has none.
     * @param  list<array<string, mixed>>  $ifraLimits  The `ifra_limit` values of the material, each with its category.
     * @return array<string, mixed>
     */
    public function option(TradeMaterial $material, Collection $prices, ?array $composition = null, array $ifraLimits = []): array
    {
        $details = array_filter([$material->grade, $material->function]);
        $rates = $this->exchangeRateLookup->forPrices($prices);

        return [
            'value' => $material->getKey(),
            'label' => $material->trade_name,
            'description' => $details === [] ? null : implode(' · ', $details),
            'status' => $material->status->value,
            'is_fragrance' => (bool) $material->is_fragrance,
            'composition' => $composition,
            'ifra_limits' => $ifraLimits,
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

    /**
     * Every IFRA limit on file for each material, with its category: the browser lets the engine pick one per category.
     *
     * @param  Collection<int, TradeMaterial>  $materials
     * @return array<string, list<array<string, mixed>>>
     */
    private function ifraLimits(Collection $materials): array
    {
        return PropertyValue::query()
            ->where('entity_type', 'trade_material')
            ->where('property', 'ifra_limit')
            ->whereNotNull('category')
            ->whereIn('entity_id', $materials->map(fn (TradeMaterial $material) => $material->getKey())->all())
            ->orderBy('category')
            ->orderByDesc('valid_from')
            ->orderBy('id')
            ->get()
            ->groupBy('entity_id')
            ->map(fn (Collection $rows): array => $rows->map(fn (PropertyValue $row): array => [
                'id' => $row->getKey(),
                'category' => $row->category,
                'value' => $row->value,
                'min' => $row->min,
                'max' => $row->max,
                'unit' => $row->unit,
                'source_id' => $row->source_id,
                'confidence' => $row->confidence->value,
                'valid_from' => $row->valid_from->toDateString(),
            ])->values()->all())
            ->all();
    }
}
