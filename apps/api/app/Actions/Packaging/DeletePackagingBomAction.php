<?php

namespace App\Actions\Packaging;

use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use Illuminate\Support\Facades\DB;

/** Deletes a BOM with its lines. Each line is deleted through its model so the audit log records it; the image file is the caller's to discard. */
final class DeletePackagingBomAction
{
    public function __invoke(PackagingBom $bom): void
    {
        DB::transaction(function () use ($bom): void {
            $bom->lines->each(fn (PackagingBomLine $line): ?bool => $line->delete());
            $bom->delete();
        });
    }
}
