<?php

namespace App\Actions\Material;

use App\Models\MaterialComposition;

/** Changes one component of a material's composition (audited like every write). */
final class UpdateMaterialCompositionAction
{
    /**
     * @param  array{cas?: string|null, inci?: string|null, pct_ww: string, source_id: string, confidence: string, valid_from: string}  $data
     */
    public function __invoke(MaterialComposition $component, array $data): MaterialComposition
    {
        $component->update($data);

        return $component;
    }
}
