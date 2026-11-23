<?php

namespace App\Services\Production;

use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds a sourced value of a material for the engine (the shape of its SourcedScalar): the reference value the formula was designed
 * with (no lot), or the value written on the COA of a given lot. Material values come first, then its substance's. Nothing is derived:
 * a value that is not on file is null, and the caller reports it missing.
 */
final class ReferenceValueFinder
{
    /** @return array{value: string, sourceId: string, confidence: string, validFrom: string, unit: string}|null */
    public function planned(TradeMaterial $material, string $property): ?array
    {
        return $this->find($material, $property, null);
    }

    /** @return array{value: string, sourceId: string, confidence: string, validFrom: string, unit: string}|null */
    public function forLot(TradeMaterial $material, string $property, string $lot): ?array
    {
        return $this->find($material, $property, $lot);
    }

    /**
     * Every property the COA of a lot gives for a material, for showing which lot values are on file.
     *
     * @return list<string>
     */
    public function lotProperties(TradeMaterial $material, string $lot): array
    {
        return $this->query($material)->where('lot_no', $lot)->whereNotNull('value')->orderBy('property')->pluck('property')->unique()->values()->all();
    }

    /** @return array{value: string, sourceId: string, confidence: string, validFrom: string, unit: string}|null */
    private function find(TradeMaterial $material, string $property, ?string $lot): ?array
    {
        $rows = $this->query($material)->where('property', $property)->whereNotNull('value')
            ->when($lot === null, fn ($query) => $query->whereNull('lot_no'), fn ($query) => $query->where('lot_no', $lot))
            ->orderByRaw("case entity_type when 'trade_material' then 0 else 1 end")
            ->orderByDesc('valid_from')
            ->orderBy('id')
            ->get();
        $row = $rows->first();

        return $row === null ? null : [
            'value' => (string) $row->value,
            'unit' => $row->unit,
            'sourceId' => $row->source_id,
            'confidence' => $row->confidence->value,
            'validFrom' => $row->valid_from->toDateString(),
        ];
    }

    /** @return Builder<PropertyValue> */
    private function query(TradeMaterial $material)
    {
        return PropertyValue::query()->where(function ($query) use ($material): void {
            $query->where(fn ($own) => $own->where('entity_type', 'trade_material')->where('entity_id', $material->getKey()));
            if ($material->substance_id !== null) {
                $query->orWhere(fn ($inherited) => $inherited->where('entity_type', 'substance')->where('entity_id', $material->substance_id));
            }
        });
    }
}
