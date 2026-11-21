<?php

namespace App\Actions\Reference;

use App\Models\Source;

/** The one shared source that every price typed into the formula editor points at (D-09). */
final class EnsureUnverifiedSourceAction
{
    public function __invoke(): Source
    {
        return Source::query()->firstOrCreate(
            ['kind' => config('formula.unverified_source.kind'), 'tenant_id' => null],
            ['title' => config('formula.unverified_source.title'), 'retrieved_at' => now()->toDateString()],
        );
    }
}
