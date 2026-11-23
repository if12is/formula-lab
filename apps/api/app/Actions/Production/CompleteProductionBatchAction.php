<?php

namespace App\Actions\Production;

use App\Enums\Production\ProductionBatchStatus;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Closes a batch. The caller (ProductionBatchService) has already checked the completion rules; this makes the closing atomic and final. */
final class CompleteProductionBatchAction
{
    /** @throws ProductionException When the batch is already closed. */
    public function __invoke(ProductionBatch $batch, ?User $user = null): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $user): ProductionBatch {
            $locked = ProductionBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw ProductionException::batchClosed($locked->batch_number);
            }

            $locked->forceFill(['status' => ProductionBatchStatus::Completed, 'completed_at' => now(), 'completed_by' => $user?->getKey()])->save();

            return $locked;
        });
    }
}
