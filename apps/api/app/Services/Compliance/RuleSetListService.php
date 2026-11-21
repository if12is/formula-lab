<?php

namespace App\Services\Compliance;

use App\Enums\RuleSetStatus;
use App\Http\Filters\ListQuery;
use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read side of the rule sets list: search, status and scope product type filters, sorting, paging. */
final class RuleSetListService
{
    /**
     * The whole `list` prop for /compliance.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $productTypeOptionsUrl): array
    {
        return ListQuery::for($this->listing(), $request)
            ->search(['code', 'name', 'scope_notes'], 'compliance.search_placeholder')
            ->select('status', RuleSetStatus::class, labelKey: 'compliance.filters.status')
            ->select(
                'product_type',
                optionsUrl: $productTypeOptionsUrl,
                labelKey: 'compliance.filters.product_type',
                using: fn (Builder $query, string $type) => $query->whereExists(fn ($rules) => $rules
                    ->from('rules')
                    ->whereColumn('rules.rule_set_id', 'rule_sets.id')
                    ->whereRaw("jsonb_exists(rules.rule -> 'scope' -> 'productTypes', ?)", [$type])),
            )
            ->dateRange('updated', 'updated_at', 'compliance.filters.updated')
            ->sortable(['code', 'name', 'status', 'updated' => 'updated_at'], default: 'updated', direction: 'desc', labelPrefix: 'compliance.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (RuleSet $ruleSet): array => [
                'id' => $ruleSet->getKey(),
                'code' => $ruleSet->code,
                'version' => $ruleSet->version,
                'name' => $ruleSet->name,
                'status' => $ruleSet->status->value,
                'rules_count' => (int) $ruleSet->getAttribute('rules_count'),
                'product_types' => json_decode((string) $ruleSet->getAttribute('product_types'), true) ?: [],
                'hash' => $ruleSet->hash,
                'updated_at' => $ruleSet->updated_at?->toDateString(),
                'href' => route('compliance.show', $ruleSet, false),
            ]);
    }

    /**
     * Every product type any rule is scoped to, for the filter and the rule form's suggestions.
     *
     * @return list<array{value: string, label: string}>
     */
    public function productTypeOptions(): array
    {
        return DB::table('rules')
            ->selectRaw("distinct jsonb_array_elements_text(rule -> 'scope' -> 'productTypes') as product_type")
            ->orderBy('product_type')
            ->pluck('product_type')
            ->map(fn (string $type): array => ['value' => $type, 'label' => $type])
            ->values()
            ->all();
    }

    /** @return Builder<RuleSet> */
    private function listing(): Builder
    {
        return RuleSet::query()
            ->select('rule_sets.*')
            ->withCount('rules')
            ->selectSub(
                "select coalesce(jsonb_agg(distinct product_type order by product_type), '[]'::jsonb)::text from rules r cross join lateral jsonb_array_elements_text(r.rule -> 'scope' -> 'productTypes') as product_type where r.rule_set_id = rule_sets.id",
                'product_types',
            );
    }
}
