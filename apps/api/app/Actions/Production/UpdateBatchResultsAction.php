<?php

namespace App\Actions\Production;

use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionBatch;

/** The figures only the end of a batch knows: the waste (against the planned one), the units made and the conversion cost really spent (Q-04, Q-09). */
final class UpdateBatchResultsAction
{
    /**
     * @param  array{planned_waste_pct?: string|null, actual_waste_g?: string|null, actual_units?: string|null, actual_conversion_egp?: string|null, conversion_note?: string|null}  $data
     *
     * @throws ProductionException When the batch is closed.
     */
    public function __invoke(ProductionBatch $batch, array $data): ProductionBatch
    {
        if (! $batch->isOpen()) {
            throw ProductionException::batchClosed($batch->batch_number);
        }

        $batch->forceFill(array_map(fn (mixed $value): mixed => $value === '' ? null : $value, array_intersect_key($data, array_flip([
            'planned_waste_pct', 'actual_waste_g', 'actual_units', 'actual_conversion_egp', 'conversion_note',
        ]))))->save();

        return $batch;
    }
}
