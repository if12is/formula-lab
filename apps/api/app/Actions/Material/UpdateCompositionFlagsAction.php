<?php

namespace App\Actions\Material;

use App\Models\TradeMaterial;

/** Sets whether a material's listed components are the whole material, and whether it is a fragrance oil. */
final class UpdateCompositionFlagsAction
{
    /** @param  array{composition_complete?: bool, is_fragrance?: bool}  $flags */
    public function __invoke(TradeMaterial $material, array $flags): TradeMaterial
    {
        $material->update($flags);

        return $material;
    }
}
