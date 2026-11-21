<?php

namespace App\Actions\Reference;

use App\Models\DatasetVersion;
use Illuminate\Database\QueryException;

/** Stores a dataset version under its content hash; an already published hash is returned as is. */
final class PublishDatasetVersionAction
{
    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws QueryException When the id is not a SHA-256 hash.
     */
    public function __invoke(string $hash, array $manifest, ?string $notes = null): DatasetVersion
    {
        return DatasetVersion::query()->find($hash) ?? DatasetVersion::query()->create([
            'id' => $hash,
            'notes' => $notes,
            'manifest' => $manifest,
        ]);
    }
}
