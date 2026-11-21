<?php

namespace App\Services\Dev;

use App\Enums\TradeMaterialStatus;
use App\Http\Filters\ListQuery;
use App\Models\TradeMaterial;
use Illuminate\Http\Request;

/** Read-side of the list-demo playground: the paginated listing and the option lookups. */
final class ListDemoService
{
    private const OPTION_PREVIEW_LIMIT = 8;

    private const SEARCH_LIMIT = 10;

    /**
     * The whole `list` prop: rows, paging meta, filter schema and what was applied.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $functionOptionsUrl): array
    {
        return ListQuery::for(TradeMaterial::query(), $request)
            ->search(['trade_name', 'grade', 'function'])
            ->select('status', TradeMaterialStatus::class)
            ->select('function', optionsUrl: $functionOptionsUrl, labelKey: 'dev.list_demo.filters.function')
            ->boolean('is_mixture', labelKey: 'dev.list_demo.filters.is_mixture')
            ->dateRange('created_at')
            ->sortable(['trade_name', 'grade', 'status', 'created_at'], default: 'trade_name', labelPrefix: 'dev.list_demo.columns')
            ->perPage(10, [10, 25, 50])
            ->paginate(fn (TradeMaterial $material): array => [
                'id' => $material->id,
                'trade_name' => $material->trade_name,
                'grade' => $material->grade,
                'function' => $material->function,
                'status' => $material->status->value,
                'is_mixture' => $material->is_mixture,
                'created_at' => $material->created_at?->toDateString(),
            ]);
    }

    /** @return list<array{value: string, label: string}> */
    public function materialOptions(): array
    {
        return TradeMaterial::query()->orderBy('trade_name')->limit(self::OPTION_PREVIEW_LIMIT)->get(['id', 'trade_name'])
            ->map(fn (TradeMaterial $material): array => ['value' => $material->id, 'label' => $material->trade_name])
            ->all();
    }

    /** @return list<array{value: string, label: string, description: string|null}> */
    public function searchMaterials(string $term): array
    {
        return TradeMaterial::query()
            ->when($term !== '', fn ($query) => $query->where('trade_name', 'ilike', '%'.addcslashes($term, '\\%_').'%'))
            ->orderBy('trade_name')
            ->limit(self::SEARCH_LIMIT)
            ->get(['id', 'trade_name', 'function'])
            ->map(fn (TradeMaterial $material): array => [
                'value' => $material->id,
                'label' => $material->trade_name,
                'description' => $material->function,
            ])
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    public function functionOptions(): array
    {
        return TradeMaterial::query()->whereNotNull('function')->distinct()->orderBy('function')->pluck('function')
            ->map(fn (string $function): array => ['value' => $function, 'label' => $function])
            ->values()
            ->all();
    }
}
