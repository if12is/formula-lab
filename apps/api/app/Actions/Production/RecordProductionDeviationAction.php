<?php

namespace App\Actions\Production;

use App\Enums\Production\DeviationApprovalStatus;
use App\Enums\Production\DeviationVerdict;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionBatch;
use App\Models\ProductionDeviation;
use App\Models\User;
use App\Support\Engine\ProductionEngine;
use Illuminate\Support\Facades\DB;

/**
 * Records a deviation (Q-11): why, who and when. When the record names a planned and an actual number the ENGINE judges it against
 * the configured threshold; above it the deviation waits for an approval. With no threshold configured every non-zero deviation waits
 * (the safe side), and the record says "threshold not set". A deviation with no two numbers to compare (a process note) is "not evaluated"
 * and needs no approval: there is nothing to measure it against.
 */
final class RecordProductionDeviationAction
{
    public function __construct(private readonly ProductionEngine $engine) {}

    /**
     * @param  array{kind: string, subject: string, planned?: string|null, actual?: string|null, unit?: string|null, reason: string, recorded_by_name: string}  $data
     *
     * @throws ProductionException When the batch is closed, only one of planned and actual is given, or the engine cannot judge them.
     */
    public function __invoke(ProductionBatch $batch, array $data, ?User $user = null): ProductionDeviation
    {
        if (! $batch->isOpen()) {
            throw ProductionException::batchClosed($batch->batch_number);
        }

        $planned = ($data['planned'] ?? '') === '' ? null : $data['planned'];
        $actual = ($data['actual'] ?? '') === '' ? null : $data['actual'];
        if (($planned === null) !== ($actual === null)) {
            throw ProductionException::deviationNeedsNumbers();
        }

        $threshold = config('production.deviation_threshold_pct');
        $verdict = DeviationVerdict::NotEvaluated;
        $pct = null;
        $requires = false;

        if ($planned !== null && $actual !== null) {
            $answer = $this->engine->deviations(['deviation' => ['planned' => $planned, 'actual' => $actual, 'unit' => $data['unit'] ?: 'g']], $threshold);
            $judged = $answer['deviation'] ?? ['ok' => false];
            if (! $judged['ok']) {
                throw ProductionException::calculationIncomplete(array_map(fn (array $gap): string => $this->reason($gap), $judged['missing'] ?? []));
            }
            $verdict = DeviationVerdict::from($judged['value']['verdict']);
            $pct = $judged['value']['differencePct']['value'];
            $requires = in_array($verdict, [DeviationVerdict::Exceeds, DeviationVerdict::ThresholdNotSet], true);
        }

        return DB::transaction(fn (): ProductionDeviation => ProductionDeviation::query()->create([
            'production_batch_id' => $batch->getKey(),
            'kind' => $data['kind'],
            'subject' => $data['subject'],
            'planned' => $planned,
            'actual' => $actual,
            'unit' => $planned === null ? null : ($data['unit'] ?: 'g'),
            'deviation_pct' => $pct,
            'threshold_pct' => $planned === null ? null : $threshold,
            'verdict' => $verdict,
            'requires_approval' => $requires,
            'approval_status' => $requires ? DeviationApprovalStatus::Pending : DeviationApprovalStatus::NotRequired,
            'reason' => trim($data['reason']),
            'recorded_by_name' => trim($data['recorded_by_name']),
            'recorded_by' => $user?->getKey(),
            'recorded_at' => now(),
        ]));
    }

    /** @param  array<string, mixed>  $gap */
    private function reason(array $gap): string
    {
        $key = 'engine.'.$gap['reason'];

        return __($key) === $key ? (string) $gap['reason'] : __($key);
    }
}
