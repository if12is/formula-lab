<?php

namespace App\Actions\Packaging;

use App\Models\PackagingBom;

/** Updates a BOM's own fields: name, SKU code, Sanad link, packing configuration, note and the pack image's record. */
final class UpdatePackagingBomAction
{
    /** @param  array<string, mixed>  $data */
    public function __invoke(PackagingBom $bom, array $data): PackagingBom
    {
        $bom->update($data);

        return $bom;
    }
}
