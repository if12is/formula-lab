<?php

namespace App\Actions\Material;

use App\Enums\Confidence;
use App\Models\Price;
use App\Models\TradeMaterial;
use App\Support\Currencies;

/** Inserts one dated reference price quote (per kilogram, in the currency it was quoted in; EGP unless told otherwise) for a trade material. */
final class CreatePriceAction
{
    /**
     * @param  array{amount: string, currency?: string|null, quoted_on: string, source_id: string, confidence: Confidence|string}  $data
     */
    public function __invoke(TradeMaterial $material, array $data, ?int $userId = null): Price
    {
        return Price::query()->create([
            ...$data,
            'currency' => $data['currency'] ?? Currencies::base(),
            'tenant_id' => $material->tenant_id,
            'trade_material_id' => $material->getKey(),
            'per_unit' => config('formula.price.per_unit'),
            'created_by' => $userId,
        ]);
    }
}
