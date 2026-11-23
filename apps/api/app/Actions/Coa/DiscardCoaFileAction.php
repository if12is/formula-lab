<?php

namespace App\Actions\Coa;

use Illuminate\Support\Facades\Storage;

/** Removes an uploaded certificate from the private disk when its extraction is abandoned. */
final class DiscardCoaFileAction
{
    public function __invoke(string $path): void
    {
        Storage::disk((string) config('coa.disk'))->delete($path);
    }
}
