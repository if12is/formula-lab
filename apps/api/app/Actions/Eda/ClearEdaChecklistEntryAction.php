<?php

namespace App\Actions\Eda;

use App\Models\EdaChecklistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Sets an item back to missing: the entry and its file are removed (the audit log keeps what it held). */
final class ClearEdaChecklistEntryAction
{
    public function __invoke(EdaChecklistEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $path = $entry->file_path;
            $entry->delete();

            if ($path !== null) {
                Storage::disk(config('eda.disk'))->delete($path);
            }
        });
    }
}
