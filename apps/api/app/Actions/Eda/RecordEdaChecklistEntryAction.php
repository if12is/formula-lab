<?php

namespace App\Actions\Eda;

use App\Enums\EdaItemStatus;
use App\Models\EdaChecklistEntry;
use App\Models\Formula;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Writes what is known of one item of a product's EDA file: the document attached (and its file) or the reason it does not apply. One row per item; a new write replaces it. */
final class RecordEdaChecklistEntryAction
{
    /**
     * @param  array{status: EdaItemStatus, document_title?: string|null, document_reference?: string|null, note?: string|null}  $data
     */
    public function __invoke(Formula $formula, string $itemCode, array $data, ?UploadedFile $file, ?User $user): EdaChecklistEntry
    {
        return DB::transaction(function () use ($formula, $itemCode, $data, $file, $user): EdaChecklistEntry {
            $entry = EdaChecklistEntry::query()->firstOrNew(['formula_id' => $formula->getKey(), 'item_code' => $itemCode]);
            $attached = $data['status'] === EdaItemStatus::Attached;
            $previousPath = $entry->file_path;

            $path = $attached ? $previousPath : null;
            $name = $attached ? $entry->file_name : null;
            if ($attached && $file !== null) {
                $path = $file->store(config('eda.directory').'/'.$formula->getKey(), config('eda.disk'));
                $name = $file->getClientOriginalName();
            }

            $entry->fill([
                'status' => $data['status'],
                'document_title' => $attached ? ($data['document_title'] ?? null) : null,
                'document_reference' => $attached ? ($data['document_reference'] ?? null) : null,
                'note' => $data['note'] ?? null,
                'file_path' => $path,
                'file_name' => $name,
                'updated_by' => $user?->getKey(),
            ])->save();

            if ($previousPath !== null && $previousPath !== $path) {
                Storage::disk(config('eda.disk'))->delete($previousPath);
            }

            return $entry;
        });
    }
}
