<?php

namespace App\Actions\Production;

use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchCheck;
use App\Support\Engine\ProductionEngine;

/**
 * Records one reading at a check point of the batch's snapshot. The engine reads it against the point's limits: with no limit
 * the status is UNKNOWN ("not evaluated"), never PASS. Readings are append-only: a correction is a newer reading.
 */
final class RecordBatchCheckAction
{
    public function __construct(private readonly ProductionEngine $engine) {}

    /**
     * @param  array{step_no: int, check_no: int, value: string, measured_by: string, measured_at: string, note?: string|null}  $data
     *
     * @throws ProductionException When the batch is closed, the check point is not in its snapshot or the engine cannot read the value.
     */
    public function __invoke(ProductionBatch $batch, array $data): ProductionBatchCheck
    {
        if (! $batch->isOpen()) {
            throw ProductionException::batchClosed($batch->batch_number);
        }

        $point = null;
        foreach ($batch->snapshot['steps'] as $step) {
            if ((int) $step['step_no'] !== $data['step_no']) {
                continue;
            }
            foreach ($step['checks'] as $check) {
                if ((int) $check['check_no'] === $data['check_no']) {
                    $point = $check;
                }
            }
        }
        if ($point === null) {
            throw ProductionException::checkNotInBatch($data['step_no'], $data['check_no']);
        }

        $answer = $this->engine->checkLimits(['value' => $data['value'], 'min' => $point['limit_min'], 'max' => $point['limit_max']]);
        if (! $answer['ok']) {
            throw ProductionException::calculationIncomplete(array_map(fn (array $gap): string => (string) __('engine.'.$gap['reason']), $answer['missing'] ?? []));
        }

        return ProductionBatchCheck::query()->create([
            'production_batch_id' => $batch->getKey(),
            'step_no' => $data['step_no'],
            'check_no' => $data['check_no'],
            'property' => $point['property'],
            'value' => $data['value'],
            'unit' => $point['unit'],
            'status' => $answer['value']['status'],
            'measured_by' => trim($data['measured_by']),
            'measured_at' => $data['measured_at'],
            'note' => $data['note'] ?? null,
        ]);
    }
}
