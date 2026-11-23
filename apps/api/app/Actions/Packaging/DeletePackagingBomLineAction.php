<?php

namespace App\Actions\Packaging;

use App\Models\PackagingBomLine;

/** Removes a line from a BOM. */
final class DeletePackagingBomLineAction
{
    public function __invoke(PackagingBomLine $line): void
    {
        $line->delete();
    }
}
