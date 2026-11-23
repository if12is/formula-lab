<?php

namespace App\Services\Production;

use App\Enums\Production\DeviationApprovalStatus;
use App\Enums\Production\DeviationKind;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchCheck;
use App\Models\ProductionBatchLine;
use App\Support\DecimalNormalizer;
use App\Support\Engine\ProductionEngine;
use Illuminate\Support\Collection;

/**
 * What the batch's numbers say now: each weighed line against what it was to weigh (the engine's verdict at the configured threshold),
 * the latest reading of each check point, and what still blocks the batch from being closed. Nothing is stored here and nothing is
 * calculated here: the engine judges, this class asks it once for the whole batch and words the blockers.
 */
final class ProductionBatchEvaluator
{
    public function __construct(private readonly ProductionEngine $engine) {}

    /**
     * Per line id: the engine's deviation of the weighed quantity from the planned weighing, for every weighed line that can be compared.
     *
     * @return array<string, array<string, mixed>>
     */
    public function lineDeviations(ProductionBatch $batch): array
    {
        $items = [];
        foreach ($batch->lines as $line) {
            if ($line->actual_quantity !== null && $this->comparable($line)) {
                $items[$line->getKey()] = ['planned' => $line->weigh_quantity, 'actual' => $line->actual_quantity, 'unit' => $line->uom];
            }
        }

        $judged = $this->engine->deviations($items, config('production.deviation_threshold_pct'));

        return array_map(fn (array $answer): array => $answer['ok'] ? $answer['value'] : ['verdict' => 'not_evaluated'], $judged);
    }

    /**
     * The newest reading of each check point, keyed "step/check".
     *
     * @return Collection<string, ProductionBatchCheck>
     */
    public function latestChecks(ProductionBatch $batch): Collection
    {
        return $batch->checks->groupBy(fn (ProductionBatchCheck $check): string => "{$check->step_no}/{$check->check_no}")
            ->map(fn (Collection $readings): ProductionBatchCheck => $readings->sortBy('id')->last());
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $lineDeviations  Already judged lines, to save a second engine call.
     * @return list<string> The reasons the batch cannot be closed yet, in words; empty when it can.
     */
    public function completionBlockers(ProductionBatch $batch, ?array $lineDeviations = null): array
    {
        $batch->loadMissing(['lines.tradeMaterial', 'checks', 'deviations']);
        $lineDeviations ??= $this->lineDeviations($batch);
        $blockers = [];

        $unweighed = $batch->lines->filter(fn (ProductionBatchLine $line): bool => $line->actual_quantity === null);
        if ($unweighed->isNotEmpty()) {
            $blockers[] = __('production.blockers.lines_not_weighed', ['count' => $unweighed->count()]);
        }

        $recorded = $batch->deviations->map(fn ($deviation): string => $deviation->kind->value.'|'.$deviation->subject)->all();
        $needing = $batch->lines->filter(function (ProductionBatchLine $line) use ($lineDeviations, $recorded): bool {
            $verdict = $lineDeviations[$line->getKey()]['verdict'] ?? null;

            return in_array($verdict, ['exceeds', 'threshold_not_set'], true) && ! in_array(DeviationKind::Weight->value.'|line:'.$line->line_no, $recorded, true);
        });
        if ($needing->isNotEmpty()) {
            $blockers[] = __('production.blockers.deviation_missing', ['lines' => $needing->pluck('line_no')->implode(', ')]);
        }

        $failed = $this->latestChecks($batch)->filter(fn (ProductionBatchCheck $check, string $key): bool => $check->status === 'FAIL' && ! in_array(DeviationKind::Check->value.'|step:'.str_replace('/', '/check:', $key), $recorded, true));
        if ($failed->isNotEmpty()) {
            $blockers[] = __('production.blockers.check_failed', ['checks' => $failed->keys()->implode(', ')]);
        }

        $pending = $batch->deviations->where('approval_status', DeviationApprovalStatus::Pending)->count();
        if ($pending > 0) {
            $blockers[] = __('production.blockers.deviations_pending', ['count' => $pending]);
        }
        $rejected = $batch->deviations->where('approval_status', DeviationApprovalStatus::Rejected)->count();
        if ($rejected > 0) {
            $blockers[] = __('production.blockers.deviations_rejected', ['count' => $rejected]);
        }

        return $blockers;
    }

    /** A line weighs 0 at the balance resolution when its share is below it: there is nothing to compare. */
    private function comparable(ProductionBatchLine $line): bool
    {
        return DecimalNormalizer::normalize((string) $line->weigh_quantity) !== '0';
    }
}
