<?php

namespace App\Services\Material;

use App\Actions\Reference\CreateSubstanceAction;
use App\Models\Substance;

/** The single write path of substances, plus the typeahead lookup used by the material form. */
final class SubstanceService
{
    public function __construct(
        private CreateSubstanceAction $createSubstance,
    ) {}

    /** @return list<array{value: string, label: string, description: string|null}> */
    public function search(string $term): array
    {
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        return Substance::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($group) => $group
                ->where('inci', 'ilike', $pattern)
                ->orWhere('cas', 'ilike', $pattern)
                ->orWhere('name_en', 'ilike', $pattern)
                ->orWhere('name_ar', 'ilike', $pattern)))
            ->orderByRaw('coalesce(inci, name_en, name_ar, cas)')
            ->limit(config('formula.search_limit'))
            ->get()
            ->map(fn (Substance $substance): array => $this->option($substance))
            ->all();
    }

    /**
     * @param  array{cas?: string|null, inci?: string|null, name_en?: string|null, name_ar?: string|null, mw?: string|null, formula?: string|null}  $data
     */
    public function create(array $data): Substance
    {
        return ($this->createSubstance)($data);
    }

    /** @return array{value: string, label: string, description: string|null} */
    public function option(Substance $substance): array
    {
        $details = array_filter([$substance->cas, $substance->name_en !== $substance->inci ? $substance->name_en : null, $substance->name_ar]);

        return [
            'value' => $substance->getKey(),
            'label' => $this->label($substance),
            'description' => $details === [] ? null : implode(' · ', $details),
        ];
    }

    public function label(Substance $substance): string
    {
        return (string) ($substance->inci ?? $substance->name_en ?? $substance->name_ar ?? $substance->cas);
    }
}
