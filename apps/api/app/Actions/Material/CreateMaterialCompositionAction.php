<?php

namespace App\Actions\Material;

use App\Models\MaterialComposition;
use App\Models\TradeMaterial;

/** Inserts one component (CAS and/or INCI at an exact % w/w, with its source) into a material's composition. */
final class CreateMaterialCompositionAction
{
    /**
     * @param  array{cas?: string|null, inci?: string|null, pct_ww: string, source_id: string, confidence: string, valid_from: string}  $data
     */
    public function __invoke(TradeMaterial $material, array $data, ?int $userId = null): MaterialComposition
    {
        return MaterialComposition::query()->create([
            ...$data,
            'trade_material_id' => $material->getKey(),
            'created_by' => $userId,
        ]);
    }
}
