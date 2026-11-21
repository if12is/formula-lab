<?php

namespace App\Services\Formula;

use App\Enums\FormulaStatus;
use App\Enums\ProductTier;
use App\Http\Filters\ListQuery;
use App\Models\Formula;
use App\Models\FormulaVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the formulas list: filtered, sorted, paginated, with each formula's latest version. */
final class FormulaListService
{
    /**
     * The whole `list` prop for /formulas.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $productTypeOptionsUrl): array
    {
        return ListQuery::for($this->listing(), $request)
            ->search(['code', 'name'], 'formulas.search_placeholder')
            ->select('product_type', optionsUrl: $productTypeOptionsUrl, labelKey: 'formulas.filters.product_type')
            ->select('tier', ProductTier::class, labelKey: 'formulas.filters.tier')
            ->select('latest_status', FormulaStatus::class, labelKey: 'formulas.filters.latest_status')
            ->dateRange('updated', 'last_activity_at', 'formulas.filters.updated')
            ->sortable(['code', 'name', 'updated' => 'last_activity_at'], default: 'updated', direction: 'desc', labelPrefix: 'formulas.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (Formula $formula): array => [
                'id' => $formula->getKey(),
                'code' => $formula->code,
                'name' => $formula->name,
                'product_type' => $formula->product_type,
                'tier' => $formula->tier?->value,
                'latest_status' => $formula->getAttribute('latest_status'),
                'latest_version' => $formula->getAttribute('latest_version'),
                'last_activity_at' => substr((string) $formula->getAttribute('last_activity_at'), 0, 10),
                'href' => route('formulas.show', $formula, false),
            ]);
    }

    /** @return list<array{value: string, label: string}> */
    public function productTypeOptions(): array
    {
        return Formula::query()->distinct()->orderBy('product_type')->pluck('product_type')
            ->map(fn (string $type): array => ['value' => $type, 'label' => $type])
            ->values()
            ->all();
    }

    /**
     * Formulas with the status and number of their latest version and the time of their last activity (the newest
     * version, or the formula row itself), so the list can filter and sort by them.
     *
     * @return Builder<Formula>
     */
    private function listing(): Builder
    {
        $latest = fn (string $column) => FormulaVersion::query()
            ->select($column)
            ->whereColumn('formula_versions.formula_id', 'formulas.id')
            ->orderByDesc('version')
            ->limit(1);

        $derived = Formula::query()
            ->select('formulas.*')
            ->selectSub($latest('status'), 'latest_status')
            ->selectSub($latest('version'), 'latest_version')
            ->selectRaw('coalesce((select max(created_at) from formula_versions where formula_versions.formula_id = formulas.id), formulas.updated_at) as last_activity_at');

        return Formula::query()->fromSub($derived, 'formulas');
    }
}
