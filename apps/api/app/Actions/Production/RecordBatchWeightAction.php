<?php

namespace App\Actions\Production;

use App\Enums\Production\BatchLineKind;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\SanadPurchaseLine;
use App\Support\Engine\ProductionEngine;
use Illuminate\Support\Facades\DB;

/**
 * Records what was really weighed (or counted) for one line, the raw-material lot it came from and what that lot cost. The cost is the
 * one typed in, or, when the lot is on a Sanad purchase line of the same product, the engine's cost per base unit of that line
 * (invoice cost, not a full landed cost). Without either the cost stays null: it is never guessed.
 */
final class RecordBatchWeightAction
{
    public function __construct(private readonly ProductionEngine $engine) {}

    /**
     * @param  array{actual_quantity: string, lot_number?: string|null, unit_cost?: string|null, weighed_by: string}  $data
     *
     * @throws ProductionException When the batch is closed or the line belongs to another batch.
     */
    public function __invoke(ProductionBatch $batch, ProductionBatchLine $line, array $data): ProductionBatchLine
    {
        return DB::transaction(function () use ($batch, $line, $data): ProductionBatchLine {
            $locked = ProductionBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw ProductionException::batchClosed($locked->batch_number);
            }
            if ($line->production_batch_id !== $locked->getKey()) {
                throw ProductionException::lineNotInBatch();
            }

            $lot = ($data['lot_number'] ?? '') === '' ? null : trim((string) $data['lot_number']);
            $cost = $this->cost($line, $lot, $data['unit_cost'] ?? null);

            $line->forceFill([
                'actual_quantity' => $data['actual_quantity'],
                'lot_number' => $lot,
                'weighed_by' => $data['weighed_by'],
                'weighed_at' => now(),
                ...$cost,
            ])->save();

            return $line;
        });
    }

    /** @return array<string, string|null> */
    private function cost(ProductionBatchLine $line, ?string $lot, ?string $typed): array
    {
        $costUom = $line->kind === BatchLineKind::RawMaterial ? 'kg' : 'unit';
        if ($typed !== null && $typed !== '') {
            return ['unit_cost' => $typed, 'cost_uom' => $costUom, 'unit_cost_source' => 'manual', 'sanad_purchase_line_uuid' => null];
        }

        $none = ['unit_cost' => null, 'cost_uom' => null, 'unit_cost_source' => null, 'sanad_purchase_line_uuid' => null];
        $productUuid = $line->tradeMaterial?->sanad_product_uuid;
        if ($lot === null || $productUuid === null) {
            return $none;
        }

        $purchase = SanadPurchaseLine::query()->where('sanad_product_uuid', $productUuid)->where('batch_number', $lot)->orderByDesc('purchase_date')->first();
        if ($purchase === null) {
            return $none;
        }

        $answer = $this->engine->lotUnitCost(['unitCost' => $purchase->unit_cost, 'quantity' => $purchase->quantity, 'baseQuantity' => $purchase->base_quantity]);
        if (! $answer['ok']) {
            return $none;
        }

        return ['unit_cost' => $answer['value']['value'], 'cost_uom' => $costUom, 'unit_cost_source' => 'sanad_purchase', 'sanad_purchase_line_uuid' => $purchase->sanad_uuid];
    }
}
