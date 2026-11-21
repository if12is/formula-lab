<?php

namespace App\Actions\Material;

use App\Enums\Confidence;
use App\Models\Price;
use App\Models\TradeMaterial;

/** Inserts one dated reference price quote (per kilogram) for a trade material. */
final class CreatePriceAction
{
    /**
     * @param  array{amount: string, quoted_on: string, source_id: string, confidence: Confidence|string}  $data
     */
    public function __invoke(TradeMaterial $material, array $data, ?int $userId = null): Price
    {
        return Price::query()->create([
            ...$data,
            'tenant_id' => $material->tenant_id,
            'trade_material_id' => $material->getKey(),
            'currency' => config('formula.price.currency'),
            'per_unit' => config('formula.price.per_unit'),
            'created_by' => $userId,
        ]);
    }
}
