<?php

namespace App\Services\Production;

use App\Enums\Production\ProductionOrderStatus;
use App\Http\Filters\ListQuery;
use App\Models\Formula;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the production orders list: filtered, sorted, paginated, each order with its formula and its batch. */
final class ProductionOrderListService
{
    /**
     * The whole `list` prop for /production/orders.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $formulaOptionsUrl): array
    {
        return ListQuery::for($this->listing(), $request)
            ->search(['code', 'batch_number', 'formula_code', 'formula_name'], 'production.orders.search_placeholder')
            ->select('formula_id', optionsUrl: $formulaOptionsUrl, labelKey: 'production.orders.filters.formula')
            ->select('status', ProductionOrderStatus::class, labelKey: 'production.orders.filters.status')
            ->select('batch_state', [
                ['value' => 'none', 'labelKey' => 'production.orders.batch_state.none'],
                ['value' => 'in_progress', 'labelKey' => 'production.orders.batch_state.in_progress'],
                ['value' => 'completed', 'labelKey' => 'production.orders.batch_state.completed'],
            ], labelKey: 'production.orders.filters.batch_state')
            ->dateRange('planned_on', labelKey: 'production.orders.filters.planned_on')
            ->sortable(['code', 'formula' => 'formula_code', 'planned' => 'planned_on', 'status', 'size' => 'batch_size'], default: 'planned', direction: 'desc', labelPrefix: 'production.orders.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (ProductionOrder $order): array => [
                'id' => $order->getKey(),
                'code' => $order->code,
                'batch_number' => $order->batch_number,
                'formula_code' => $order->getAttribute('formula_code'),
                'formula_name' => $order->getAttribute('formula_name'),
                'version' => $order->getAttribute('version_number'),
                'status' => $order->status->value,
                'planned_on' => $order->planned_on?->toDateString(),
                'batch_size' => $order->batch_size,
                'batch_unit' => $order->batch_unit,
                'batch_state' => $order->getAttribute('batch_state'),
                'href' => route('production.orders.show', $order, false),
            ]);
    }

    /** @return list<array{value: string, label: string}> */
    public function formulaOptions(): array
    {
        return Formula::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Formula $formula): array => ['value' => $formula->getKey(), 'label' => "{$formula->code} — {$formula->name}"])
            ->all();
    }

    /** @return Builder<ProductionOrder> */
    private function listing(): Builder
    {
        $derived = ProductionOrder::query()
            ->select('production_orders.*')
            ->selectRaw('formulas.id as formula_id, formulas.code as formula_code, formulas.name as formula_name')
            ->selectRaw('formula_versions.version as version_number')
            ->selectRaw("coalesce((select status from production_batches where production_batches.production_order_id = production_orders.id), 'none') as batch_state")
            ->join('formula_versions', 'formula_versions.id', '=', 'production_orders.formula_version_id')
            ->join('formulas', 'formulas.id', '=', 'formula_versions.formula_id');

        return ProductionOrder::query()->fromSub($derived, 'production_orders');
    }
}
