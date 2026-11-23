<?php

namespace App\Actions\Packaging;

use App\Models\PackagingBomLine;

/** Changes a BOM line's component, level, quantity, reject %, price and source. */
final class UpdatePackagingBomLineAction
{
    /** @param  array<string, mixed>  $data */
    public function __invoke(PackagingBomLine $line, array $data): PackagingBomLine
    {
        $line->update($data);

        return $line;
    }
}
