<?php

namespace App\Actions\Packaging;

use App\Models\PackagingBom;
use App\Models\PackagingBomLine;

/** Adds a packaging component to a BOM, after its last line. */
final class CreatePackagingBomLineAction
{
    /** @param  array<string, mixed>  $data */
    public function __invoke(PackagingBom $bom, array $data): PackagingBomLine
    {
        $position = $bom->lines()->max('position');

        return $bom->lines()->create([...$data, 'position' => $position === null ? 0 : (int) $position + 1]);
    }
}
