<?php

namespace App\Services\Production;

use App\Actions\Production\CompleteProductionBatchAction;
use App\Actions\Production\DecideProductionDeviationAction;
use App\Actions\Production\RecordBatchCheckAction;
use App\Actions\Production\RecordBatchWeightAction;
use App\Actions\Production\RecordProductionDeviationAction;
use App\Actions\Production\StartProductionBatchAction;
use App\Actions\Production\UpdateBatchResultsAction;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchCheck;
use App\Models\ProductionBatchLine;
use App\Models\ProductionDeviation;
use App\Models\ProductionOrder;
use App\Models\User;

/** The single write path of a production batch: its start, weights, readings, deviations, results and closing (Q-02, Q-11, Q-04, Q-09). */
final class ProductionBatchService
{
    public function __construct(
        private readonly StartProductionBatchAction $startBatch,
        private readonly RecordBatchWeightAction $recordWeight,
        private readonly RecordBatchCheckAction $recordCheck,
        private readonly RecordProductionDeviationAction $recordDeviation,
        private readonly DecideProductionDeviationAction $decideDeviation,
        private readonly UpdateBatchResultsAction $updateResults,
        private readonly CompleteProductionBatchAction $completeBatch,
        private readonly ProductionBatchEvaluator $evaluator,
        private readonly ProductionAudience $audience,
    ) {}

    /** @throws ProductionException */
    public function start(ProductionOrder $order, string $operator, ?string $plannedWastePct, ?User $user = null): ProductionBatch
    {
        return ($this->startBatch)($order, $operator, $plannedWastePct, $user);
    }

    /**
     * @param  array{actual_quantity: string, lot_number?: string|null, unit_cost?: string|null, weighed_by: string}  $data
     *
     * @throws ProductionException
     */
    public function weigh(ProductionBatch $batch, ProductionBatchLine $line, array $data): ProductionBatchLine
    {
        return ($this->recordWeight)($batch, $line, $data);
    }

    /**
     * @param  array{step_no: int, check_no: int, value: string, measured_by: string, measured_at: string, note?: string|null}  $data
     *
     * @throws ProductionException
     */
    public function check(ProductionBatch $batch, array $data): ProductionBatchCheck
    {
        return ($this->recordCheck)($batch, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ProductionException
     */
    public function deviate(ProductionBatch $batch, array $data, ?User $user = null): ProductionDeviation
    {
        return ($this->recordDeviation)($batch, $data, $user);
    }

    /** @throws ProductionException When the person may not approve or the deviation was already decided. */
    public function decide(ProductionDeviation $deviation, bool $approve, string $note, ?User $user): ProductionDeviation
    {
        if ($user === null || ! $this->audience->allowsApproval($user)) {
            throw ProductionException::approverRequired();
        }

        return ($this->decideDeviation)($deviation, $approve, $note, $user);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ProductionException
     */
    public function results(ProductionBatch $batch, array $data): ProductionBatch
    {
        return ($this->updateResults)($batch, $data);
    }

    /** @throws ProductionException When the batch is closed, or something still blocks closing it. */
    public function complete(ProductionBatch $batch, ?User $user = null): ProductionBatch
    {
        $blockers = $this->evaluator->completionBlockers($batch->fresh());
        if ($blockers !== []) {
            throw ProductionException::cannotComplete($batch->batch_number, $blockers);
        }

        return ($this->completeBatch)($batch, $user);
    }
}
