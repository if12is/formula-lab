<?php

namespace App\Services\Material;

use App\Actions\Reference\CreateSourceAction;
use App\Actions\Reference\EnsureUnverifiedSourceAction;
use App\Enums\SourceKind;
use App\Models\Source;

/** The single write path of data sources, plus the typeahead lookup used by the property and price forms. */
final class SourceService
{
    public function __construct(
        private CreateSourceAction $createSource,
        private EnsureUnverifiedSourceAction $ensureUnverifiedSource,
    ) {}

    /** @return list<array{value: string, label: string, description: string, retrieved_at: string}> */
    public function search(string $term): array
    {
        return Source::query()
            ->when($term !== '', fn ($query) => $query->where('title', 'ilike', '%'.addcslashes($term, '\\%_').'%'))
            ->orderBy('title')
            ->limit(config('formula.search_limit'))
            ->get()
            ->map(fn (Source $source): array => $this->option($source))
            ->all();
    }

    /**
     * @param  array{kind: string, title: string, url?: string|null, license?: string|null, retrieved_at: string}  $data
     */
    public function create(array $data): Source
    {
        return ($this->createSource)($data);
    }

    /** The shared source behind prices typed into the formula editor (D-09). */
    public function unverifiedEntry(): Source
    {
        return ($this->ensureUnverifiedSource)();
    }

    /**
     * `retrieved_at` travels with the option: a value typed against this source is dated by it (calculator inputs).
     *
     * @return array{value: string, label: string, description: string, retrieved_at: string}
     */
    public function option(Source $source): array
    {
        return [
            'value' => $source->getKey(),
            'label' => $source->kind === SourceKind::UnverifiedEntry->value ? SourceKind::UnverifiedEntry->label() : $source->title,
            'description' => SourceKind::labelFor($source->kind).' · '.$source->retrieved_at->toDateString(),
            'retrieved_at' => $source->retrieved_at->toDateString(),
        ];
    }
}
