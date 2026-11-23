<?php

namespace App\Actions\Production;

use App\Enums\Production\BatchLineKind;
use App\Enums\Production\ProductionBatchStatus;
use App\Enums\Production\ProductionOrderStatus;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Support\Engine\ProductionEngine;
use App\Support\Production\ProductionSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Starts the batch of an approved production order (Q-11): a copy of the order's snapshot and hash, and one line per ingredient
 * (in the engine's weighing order) and per packaging item, each with what is planned and what the operator is to weigh.
 */
final class StartProductionBatchAction
{
    public function __construct(private readonly ProductionEngine $engine) {}

    /** @throws ProductionException When the order is not approved, already has its batch, or cannot be weighed. */
    public function __invoke(ProductionOrder $order, string $operator, ?string $plannedWastePct = null, ?User $user = null): ProductionBatch
    {
        return DB::transaction(function () use ($order, $operator, $plannedWastePct, $user): ProductionBatch {
            $locked = ProductionOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [ProductionOrderStatus::Approved, ProductionOrderStatus::Sent], true)) {
                throw ProductionException::orderNotApproved($locked->code);
            }
            if (ProductionBatch::query()->where('production_order_id', $locked->getKey())->exists()) {
                throw ProductionException::batchAlreadyStarted($locked->code);
            }

            $sheet = $this->engine->weighingSheet([
                'lines' => ProductionSnapshot::weighingLines($locked->snapshot),
                'batchMass' => ['value' => $locked->batch_size, 'unit' => $locked->batch_unit],
                'resolutionG' => $locked->scale_resolution_g,
            ]);
            if (! $sheet['ok']) {
                throw ProductionException::notWeighable(array_map(fn (array $gap): string => (string) $gap['reason'], $sheet['missing'] ?? []));
            }

            $batch = ProductionBatch::query()->create([
                'tenant_id' => $locked->tenant_id,
                'production_order_id' => $locked->getKey(),
                'batch_number' => $locked->batch_number,
                'status' => ProductionBatchStatus::InProgress,
                'formula_version_id' => $locked->formula_version_id,
                'formula_hash' => $locked->formula_hash,
                'snapshot' => $locked->snapshot,
                'operator' => $operator,
                'started_at' => now(),
                'planned_waste_pct' => $plannedWastePct,
                'created_by' => $user?->getKey(),
            ]);

            $lineNo = 0;
            foreach ($sheet['value']['lines'] as $line) {
                ProductionBatchLine::query()->create([
                    'production_batch_id' => $batch->getKey(),
                    'line_no' => ++$lineNo,
                    'kind' => BatchLineKind::RawMaterial,
                    'trade_material_id' => $line['materialId'],
                    'phase' => $line['phase'],
                    'uom' => 'g',
                    'planned_quantity' => $line['exactG'],
                    'weigh_quantity' => $line['weighG'],
                ]);
            }
            foreach ($locked->snapshot['packaging'] as $line) {
                ProductionBatchLine::query()->create([
                    'production_batch_id' => $batch->getKey(),
                    'line_no' => ++$lineNo,
                    'kind' => BatchLineKind::Packaging,
                    'trade_material_id' => $line['trade_material_id'],
                    'uom' => 'unit',
                    'planned_quantity' => $line['quantity'],
                    'weigh_quantity' => $line['quantity'],
                ]);
            }

            return $batch;
        });
    }
}
