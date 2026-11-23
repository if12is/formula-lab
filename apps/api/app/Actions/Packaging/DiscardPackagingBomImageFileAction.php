<?php

namespace App\Actions\Packaging;

use Illuminate\Support\Facades\Storage;

/** Removes a pack image file from the private disk (a replaced or removed image, or a deleted BOM). */
final class DiscardPackagingBomImageFileAction
{
    public function __invoke(?string $path): void
    {
        if ($path !== null) {
            Storage::disk((string) config('packaging.image.disk'))->delete($path);
        }
    }
}
