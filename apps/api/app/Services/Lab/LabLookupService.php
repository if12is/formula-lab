<?php

namespace App\Services\Lab;

use App\Models\Formula;
use App\Models\FormulaVersion;
use Illuminate\Database\Eloquent\Builder;

/** Typeahead of formula versions for the trial form: "F-0001 — Name · v3 (Draft)". */
final class LabLookupService
{
    /** @return list<array{value: string, label: string, description: string}> */
    public function versions(string $term): array
    {
        $term = trim($term);

        return FormulaVersion::query()
            ->with('formula')
            ->when($term !== '', fn (Builder $query) => $query->whereHas('formula', function (Builder $formula) use ($term): void {
                $pattern = '%'.addcslashes($term, '\\%_').'%';
                $formula->where('code', 'ilike', $pattern)->orWhere('name', 'ilike', $pattern);
            }))
            ->orderBy(Formula::query()->select('code')->whereColumn('formulas.id', 'formula_versions.formula_id')->limit(1))
            ->orderByDesc('version')
            ->limit((int) config('formula.search_limit'))
            ->get()
            ->map(fn (FormulaVersion $version): array => $this->option($version))
            ->all();
    }

    /** @return array{value: string, label: string, description: string} */
    public function option(FormulaVersion $version): array
    {
        $version->loadMissing('formula');

        return [
            'value' => $version->getKey(),
            'label' => "{$version->formula->code} — {$version->formula->name} · v{$version->version}",
            'description' => $version->status->label(),
        ];
    }
}
