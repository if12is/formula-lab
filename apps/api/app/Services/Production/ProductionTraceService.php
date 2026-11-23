<?php

namespace App\Services\Production;

use App\Enums\Sanad\SanadOutboxStatus;
use App\Models\ProductionBatchLine;
use App\Services\Production\Concerns\ReadsSanadResult;

/**
 * Q-05: where a raw-material lot went. Given a lot number, every batch whose weighing recorded it, with the order, the formula version,
 * how much was used and what Sanad answered for the order (its finished-good batch, to look the sales up there). Sales are Sanad's:
 * nothing about a sale is stored or shown here beyond the reference that finds it.
 */
final class ProductionTraceService
{
    use ReadsSanadResult;

    /** @return list<array<string, mixed>> */
    public function byLot(string $lot, ?string $materialId = null): array
    {
        $lot = trim($lot);
        if ($lot === '') {
            return [];
        }

        return ProductionBatchLine::query()
            ->with(['tradeMaterial', 'batch.order', 'batch.formulaVersion.formula'])
            ->where('lot_number', $lot)
            ->when($materialId !== null && $materialId !== '', fn ($query) => $query->where('trade_material_id', $materialId))
            ->orderBy('weighed_at')
            ->get()
            ->map(function (ProductionBatchLine $line): array {
                $batch = $line->batch;
                $order = $batch->order;
                $outbox = $order->latestOutbox();

                return [
                    'id' => $line->getKey(),
                    'material_id' => $line->trade_material_id,
                    'material' => $line->tradeMaterial->trade_name,
                    'lot' => $line->lot_number,
                    'quantity' => $line->actual_quantity,
                    'uom' => $line->uom,
                    'weighed_at' => $line->weighed_at?->toIso8601String(),
                    'batch' => ['id' => $batch->getKey(), 'number' => $batch->batch_number, 'status' => $batch->status->value, 'href' => route('production.batches.show', $batch, false)],
                    'order' => ['code' => $order->code, 'status' => $order->status->value, 'href' => route('production.orders.show', $order, false)],
                    'formula' => ['code' => $batch->formulaVersion->formula->code, 'name' => $batch->formulaVersion->formula->name, 'version' => $batch->formulaVersion->version],
                    'sanad' => [
                        'status' => $outbox?->status->value ?? ($order->sent_at === null ? null : SanadOutboxStatus::Pending->value),
                        'finished_good_batch' => $this->productBatchUuid($outbox),
                    ],
                ];
            })->all();
    }

    /**
     * The lots a batch used (for the batch page): the other direction of the same trace.
     *
     * @return list<array<string, mixed>>
     */
    public function lotsOfBatch(string $batchId): array
    {
        return ProductionBatchLine::query()->with('tradeMaterial')->where('production_batch_id', $batchId)->whereNotNull('lot_number')->orderBy('line_no')->get()
            ->map(fn (ProductionBatchLine $line): array => [
                'material' => $line->tradeMaterial->trade_name,
                'lot' => $line->lot_number,
                'href' => route('production.trace', ['lot' => $line->lot_number, 'material' => $line->trade_material_id], false),
            ])->all();
    }
}
