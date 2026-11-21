<?php

namespace App\Actions\Material;

use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;

/** Inserts one sourced property value (a single value or a min/max range) on a trade material. */
final class CreatePropertyValueAction
{
    /**
     * @param  array{property: string, value?: string|null, min?: string|null, max?: string|null, unit: string, source_id: string, confidence: string, valid_from: string, lot_no?: string|null}  $data
     *
     * @throws QueryException When the database refuses the row (no value at all, min above max).
     */
    public function __invoke(TradeMaterial $material, array $data, ?int $userId = null): PropertyValue
    {
        return PropertyValue::query()->create([
            ...$data,
            'tenant_id' => $material->tenant_id,
            'entity_type' => 'trade_material',
            'entity_id' => $material->getKey(),
            'created_by' => $userId,
        ]);
    }
}
