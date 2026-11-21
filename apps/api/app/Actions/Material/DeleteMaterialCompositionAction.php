<?php

namespace App\Actions\Material;

use App\Models\MaterialComposition;

/** Removes one component from a material's composition. */
final class DeleteMaterialCompositionAction
{
    public function __invoke(MaterialComposition $component): void
    {
        $component->delete();
    }
}
