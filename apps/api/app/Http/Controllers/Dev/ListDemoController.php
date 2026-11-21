<?php

namespace App\Http\Controllers\Dev;

use App\Enums\TradeMaterialStatus;
use App\Http\Controllers\Controller;
use App\Http\Filters\ListQuery;
use App\Models\TradeMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Test-only playground (routes exist only in local and testing) that exercises the
 * shared list building blocks: ListQuery on the server, ListFilters / useListQuery /
 * EntitySelect on the client. Real index pages follow the same shape (S-03 onwards).
 */
class ListDemoController extends Controller
{
    public function index(Request $request): Response
    {
        $list = ListQuery::for(TradeMaterial::query(), $request)
            ->search(['trade_name', 'grade', 'function'])
            ->select('status', TradeMaterialStatus::class)
            ->select('function', optionsUrl: route('dev.list-demo.functions'), labelKey: 'dev.list_demo.filters.function')
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

        return Inertia::render('Dev/ListDemo', [
            'list' => $list,
            'materialOptions' => TradeMaterial::query()->orderBy('trade_name')->limit(8)->get(['id', 'trade_name'])
                ->map(fn (TradeMaterial $material): array => ['value' => $material->id, 'label' => $material->trade_name])
                ->all(),
            'searchUrl' => route('dev.list-demo.materials'),
        ]);
    }

    /** JSON for server-searched selects: [{ value, label, description }]. */
    public function materials(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $results = TradeMaterial::query()
            ->when($term !== '', fn ($query) => $query->where('trade_name', 'ilike', '%'.addcslashes($term, '\\%_').'%'))
            ->orderBy('trade_name')
            ->limit(10)
            ->get(['id', 'trade_name', 'function'])
            ->map(fn (TradeMaterial $material): array => [
                'value' => $material->id,
                'label' => $material->trade_name,
                'description' => $material->function,
            ]);

        return response()->json($results);
    }

    /** JSON options for the "function" filter: [{ value, label }]. */
    public function functions(): JsonResponse
    {
        $functions = TradeMaterial::query()->whereNotNull('function')->distinct()->orderBy('function')->pluck('function');

        return response()->json($functions->map(fn (string $function): array => ['value' => $function, 'label' => $function])->values());
    }
}
