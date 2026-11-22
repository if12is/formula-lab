<?php

namespace App\Services\Material;

use App\Models\AuditLog;
use App\Models\MaterialComposition;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use App\Services\Currency\ExchangeRateLookupService;
use App\Services\Fragrance\FragranceProfileService;

/** Read side of one material's page: its facts, sourced properties, price quotes and audit trail. */
final class MaterialDetailService
{
    /** Audit columns that say nothing a person needs (ids, bookkeeping). */
    private const AUDIT_HIDDEN_FIELDS = ['id', 'tenant_id', 'entity_type', 'entity_id', 'trade_material_id', 'created_by', 'created_at', 'updated_at', 'source_id'];

    public function __construct(
        private SubstanceService $substanceService,
        private ExchangeRateLookupService $exchangeRateLookup,
        private MaterialCompositionService $compositionService,
        private FragranceProfileService $fragranceProfile,
    ) {}

    /**
     * @return array{material: array<string, mixed>, properties: list<array<string, mixed>>, prices: list<array<string, mixed>>, composition: list<array<string, mixed>>, fragrance: array<string, mixed>|null, audit: list<array<string, mixed>>}
     */
    public function detail(TradeMaterial $material): array
    {
        $material->load('substance');
        $properties = $this->properties($material);
        $prices = $this->prices($material);

        return [
            'material' => $this->summary($material, $prices),
            'properties' => $properties,
            'prices' => $prices,
            'composition' => $this->compositionService->rows($material),
            'fragrance' => $material->is_fragrance ? $this->fragranceProfile->profile($material) : null,
            'audit' => $this->audit($material),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $prices
     * @return array<string, mixed>
     */
    private function summary(TradeMaterial $material, array $prices): array
    {
        return [
            'id' => $material->getKey(),
            'trade_name' => $material->trade_name,
            'grade' => $material->grade,
            'function' => $material->function,
            'functional_classes' => $material->functional_classes ?? [],
            'status' => $material->status->value,
            'is_mixture' => $material->is_mixture,
            'composition_complete' => $material->composition_complete,
            'is_fragrance' => $material->is_fragrance,
            'sanad_linked' => $material->sanad_product_uuid !== null,
            'substance' => $material->substance === null ? null : [
                'id' => $material->substance->getKey(),
                'label' => $this->substanceService->label($material->substance),
                'description' => $this->substanceService->option($material->substance)['description'],
                'cas' => $material->substance->cas,
                'inci' => $material->substance->inci,
            ],
            'worst_price_confidence' => $prices === [] ? null : max(array_column($prices, 'confidence')),
            'created_at' => $material->created_at?->toDateString(),
            'updated_at' => $material->updated_at?->toDateString(),
        ];
    }

    /**
     * The material's own values and those of its substance, all handed to the engine's resolveValue on the page.
     *
     * @return list<array<string, mixed>>
     */
    public function properties(TradeMaterial $material): array
    {
        return PropertyValue::query()
            ->with('source')
            ->where(function ($query) use ($material): void {
                $query->where(fn ($own) => $own->where('entity_type', 'trade_material')->where('entity_id', $material->getKey()));

                if ($material->substance_id !== null) {
                    $query->orWhere(fn ($inherited) => $inherited->where('entity_type', 'substance')->where('entity_id', $material->substance_id));
                }
            })
            ->orderBy('property')
            ->orderByDesc('valid_from')
            ->orderBy('id')
            ->get()
            ->map(fn (PropertyValue $value): array => [
                'id' => $value->getKey(),
                'origin' => $value->entity_type === 'trade_material' ? 'material' : 'substance',
                'property' => $value->property,
                'value' => $value->value,
                'min' => $value->min,
                'max' => $value->max,
                'unit' => $value->unit,
                'source_id' => $value->source_id,
                'source_title' => $value->source->title,
                'source_kind' => $value->source->kind,
                'confidence' => $value->confidence->value,
                'valid_from' => $value->valid_from->toDateString(),
                'lot_no' => $value->lot_no,
                'category' => $value->category,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function prices(TradeMaterial $material): array
    {
        $prices = Price::query()
            ->with('source')
            ->where('trade_material_id', $material->getKey())
            ->orderByDesc('quoted_on')
            ->orderBy('id')
            ->get();
        $rates = $this->exchangeRateLookup->forPrices($prices);

        return $prices
            ->map(fn (Price $price): array => [
                'id' => $price->getKey(),
                'amount' => $price->amount,
                'currency' => $price->currency,
                'per_unit' => $price->per_unit,
                'quoted_on' => $price->quoted_on->toDateString(),
                'source_id' => $price->source_id,
                'source_title' => $price->source->title,
                'source_kind' => $price->source->kind,
                'confidence' => $price->confidence->value,
                'fx' => $rates[(string) $price->getKey()],
            ])
            ->all();
    }

    /**
     * Newest first: the material itself, and every property value and price that ever belonged to it
     * (deleted ones too: their rows are found through the ids kept inside the audit payload).
     *
     * @return list<array<string, mixed>>
     */
    private function audit(TradeMaterial $material): array
    {
        $id = (string) $material->getKey();
        $propertyIds = PropertyValue::query()->where('entity_type', 'trade_material')->where('entity_id', $id)->pluck('id')->all();
        $priceIds = Price::query()->where('trade_material_id', $id)->pluck('id')->all();
        // Components that were deleted since still have their create and delete rows, which carry the material id.
        $componentIds = array_values(array_unique([
            ...MaterialComposition::query()->where('trade_material_id', $id)->pluck('id')->all(),
            ...AuditLog::query()->where('table_name', 'material_compositions')->whereRaw("coalesce(after, before) ->> 'trade_material_id' = ?", [$id])->pluck('record_id')->all(),
        ]));

        return AuditLog::query()
            ->where(function ($query) use ($id, $propertyIds, $priceIds, $componentIds): void {
                $query->where(fn ($own) => $own->where('table_name', 'trade_materials')->where('record_id', $id))
                    ->orWhere(fn ($values) => $values->where('table_name', 'property_values')->where(fn ($match) => $match
                        ->whereIn('record_id', $propertyIds)
                        ->orWhereRaw("coalesce(after, before) ->> 'entity_id' = ?", [$id])))
                    ->orWhere(fn ($quotes) => $quotes->where('table_name', 'prices')->where(fn ($match) => $match
                        ->whereIn('record_id', $priceIds)
                        ->orWhereRaw("coalesce(after, before) ->> 'trade_material_id' = ?", [$id])))
                    ->orWhere(fn ($components) => $components->where('table_name', 'material_compositions')->where(fn ($match) => $match
                        ->whereIn('record_id', $componentIds)
                        ->orWhereRaw("coalesce(after, before) ->> 'trade_material_id' = ?", [$id])));
            })
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->limit(config('formula.audit_limit'))
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'id' => $entry->getKey(),
                'at' => $entry->at?->toIso8601String(),
                'action' => $entry->action->value,
                'table' => $entry->table_name,
                'actor_id' => $entry->actor_id,
                'reason' => $entry->reason,
                'changes' => $this->changes($entry),
            ])
            ->all();
    }

    /**
     * What a person needs from an audit row: the fields, and for an update the value before and after.
     *
     * @return list<array{field: string, before: string|null, after: string|null}>
     */
    private function changes(AuditLog $entry): array
    {
        $before = $entry->before ?? [];
        $after = $entry->after ?? [];
        $fields = array_values(array_diff(array_unique([...array_keys($before), ...array_keys($after)]), self::AUDIT_HIDDEN_FIELDS));

        $changes = [];
        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ($old === null && $new === null) {
                continue;
            }

            $changes[] = ['field' => $field, 'before' => $this->scalar($old), 'after' => $this->scalar($new)];
        }

        return $changes;
    }

    private function scalar(mixed $value): ?string
    {
        return $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }
}
