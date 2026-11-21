<?php

namespace App\Actions\Reference;

use App\Models\Source;

/** Inserts one data source (a certificate, a data sheet, a standard, a database). */
final class CreateSourceAction
{
    /**
     * @param  array{kind: string, title: string, url?: string|null, license?: string|null, retrieved_at: string, tenant_id?: string|null}  $data
     */
    public function __invoke(array $data): Source
    {
        return Source::query()->create($data);
    }
}
