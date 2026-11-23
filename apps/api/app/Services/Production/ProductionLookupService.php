<?php

namespace App\Services\Production;

use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Builder;

/** Typeaheads of the production order form: APPROVED formula versions and packaging items. */
final class ProductionLookupService
{
    /** @return list<array{value: string, label: string, description: string}> */
    public function approvedVersions(string $term): array
    {
        $term = trim($term);

        return FormulaVersion::query()
            ->with('formula')
            ->where('status', FormulaStatus::Approved)
            ->when($term !== '', fn (Builder $query) => $query->whereHas('formula', function (Builder $formula) use ($term): void {
                $pattern = '%'.addcslashes($term, '\\%_').'%';
                $formula->where('code', 'ilike', $pattern)->orWhere('name', 'ilike', $pattern);
            }))
            ->orderBy(Formula::query()->select('code')->whereColumn('formulas.id', 'formula_versions.formula_id')->limit(1))
            ->orderByDesc('version')
            ->limit((int) config('formula.search_limit'))
            ->get()
            ->map(fn (FormulaVersion $version): array => $this->versionOption($version))
            ->all();
    }

    /** @return array{value: string, label: string, description: string} */
    public function versionOption(FormulaVersion $version): array
    {
        $version->loadMissing('formula');

        return [
            'value' => $version->getKey(),
            'label' => "{$version->formula->code} — {$version->formula->name} · v{$version->version}",
            'description' => $version->status->label(),
        ];
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public function packagingItems(string $term): array
    {
        $term = trim($term);

        return TradeMaterial::query()->packaging()
            ->when($term !== '', fn (Builder $query) => $query->where('trade_name', 'ilike', '%'.addcslashes($term, '\\%_').'%'))
            ->orderBy('trade_name')
            ->limit((int) config('formula.search_limit'))
            ->get()
            ->map(fn (TradeMaterial $material): array => ['value' => $material->getKey(), 'label' => $material->trade_name, 'description' => $material->item_kind->label()])
            ->all();
    }
}
