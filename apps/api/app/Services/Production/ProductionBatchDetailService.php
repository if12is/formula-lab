<?php

namespace App\Services\Production;

use App\Enums\Production\DeviationKind;
use App\Enums\Production\ProcessStepKind;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchCheck;
use App\Models\ProductionBatchLine;
use App\Models\ProductionDeviation;
use App\Models\User;
use App\Support\DecimalNormalizer;

/**
 * Everything the batch page shows: the snapshot's steps and check points with their readings, the lines with what was weighed, the lot
 * and the engine's verdict on each, the deviations with their approvals, what still blocks closing, and the waste, alkali (COA lots) and
 * cost comparisons. A weights-only role gets none of the money (line costs, the cost comparison): the keys are left out.
 */
final class ProductionBatchDetailService
{
    public function __construct(
        private readonly ProductionBatchEvaluator $evaluator,
        private readonly ProductionSheetService $sheets,
        private readonly BatchWasteService $waste,
        private readonly BatchAlkaliService $alkali,
        private readonly BatchCostService $cost,
        private readonly ProductionTraceService $trace,
        private readonly ProductionAudience $audience,
    ) {}

    /** @return array<string, mixed> */
    public function forBatch(ProductionBatch $batch, ?User $user, ?string $superfatPct = null): array
    {
        $batch->load(['order', 'formulaVersion.formula', 'lines.tradeMaterial', 'checks', 'deviations']);
        $seesPrices = $this->audience->seesPrices($user);
        $deviations = $this->evaluator->lineDeviations($batch);
        $latest = $this->evaluator->latestChecks($batch);
        $recorded = $batch->deviations->map(fn (ProductionDeviation $deviation): string => $deviation->kind->value.'|'.$deviation->subject)->all();
        $sheet = $this->sheets->forOrder($batch);
        $formula = $batch->formulaVersion->formula;
        $names = array_column($batch->snapshot['lines'], 'material', 'line_no');

        $props = [
            'batch' => [
                'id' => $batch->getKey(),
                'number' => $batch->batch_number,
                'status' => $batch->status->value,
                'open' => $batch->isOpen(),
                'operator' => $batch->operator,
                'started_at' => $batch->started_at->toIso8601String(),
                'completed_at' => $batch->completed_at?->toIso8601String(),
                'planned_waste_pct' => $batch->planned_waste_pct === null ? null : DecimalNormalizer::normalize($batch->planned_waste_pct),
                'actual_waste_g' => $batch->actual_waste_g === null ? null : DecimalNormalizer::normalize($batch->actual_waste_g),
                'actual_units' => $batch->actual_units === null ? null : DecimalNormalizer::normalize($batch->actual_units),
                'actual_conversion_egp' => $batch->actual_conversion_egp === null ? null : DecimalNormalizer::normalize($batch->actual_conversion_egp),
                'conversion_note' => $batch->conversion_note,
                'formula_hash' => $batch->formula_hash,
            ],
            'order' => ['code' => $batch->order->code, 'href' => route('production.orders.show', $batch->order, false), 'batch_size' => $batch->order->batch_size, 'batch_unit' => $batch->order->batch_unit],
            'formula' => ['code' => $formula->code, 'name' => $formula->name, 'version' => $batch->formulaVersion->version],
            'lines' => $batch->lines->map(function (ProductionBatchLine $line) use ($deviations, $recorded, $seesPrices): array {
                $row = [
                    'id' => $line->getKey(),
                    'line_no' => $line->line_no,
                    'kind' => $line->kind->value,
                    'material' => $line->tradeMaterial->trade_name,
                    'material_id' => $line->trade_material_id,
                    'phase' => $line->phase,
                    'uom' => $line->uom,
                    'planned' => DecimalNormalizer::normalize($line->planned_quantity),
                    'weigh' => $line->weigh_quantity,
                    'actual' => $line->actual_quantity === null ? null : DecimalNormalizer::normalize($line->actual_quantity),
                    'lot' => $line->lot_number,
                    'weighed_by' => $line->weighed_by,
                    'weighed_at' => $line->weighed_at?->toIso8601String(),
                    'deviation' => isset($deviations[$line->getKey()]) ? [
                        'verdict' => $deviations[$line->getKey()]['verdict'],
                        'difference' => $deviations[$line->getKey()]['difference']['value'] ?? null,
                        'pct' => $deviations[$line->getKey()]['differencePctDisplay'] ?? null,
                    ] : null,
                    'deviation_recorded' => in_array(DeviationKind::Weight->value.'|line:'.$line->line_no, $recorded, true),
                ];
                if ($seesPrices) {
                    $row['unit_cost'] = $line->unit_cost === null ? null : DecimalNormalizer::normalize($line->unit_cost);
                    $row['cost_uom'] = $line->cost_uom;
                    $row['unit_cost_source'] = $line->unit_cost_source;
                }

                return $row;
            })->all(),
            'steps' => array_map(fn (array $step): array => [
                ...$step,
                'material' => $step['line_no'] === null ? null : ($names[$step['line_no']] ?? null),
                'checks' => array_map(function (array $check) use ($step, $latest, $batch): array {
                    $reading = $latest->get("{$step['step_no']}/{$check['check_no']}");

                    return [
                        ...$check,
                        'latest' => $reading === null ? null : $this->reading($reading),
                        'readings' => $batch->checks->where('step_no', (int) $step['step_no'])->where('check_no', (int) $check['check_no'])->count(),
                        'deviation_recorded' => in_array(DeviationKind::Check->value."|step:{$step['step_no']}/check:{$check['check_no']}", $this->recordedOf($batch), true),
                    ];
                }, $step['checks']),
            ], $batch->snapshot['steps']),
            'deviations' => $batch->deviations->map(fn (ProductionDeviation $deviation): array => [
                'id' => $deviation->getKey(),
                'kind' => $deviation->kind->value,
                'subject' => $deviation->subject,
                'planned' => $deviation->planned === null ? null : DecimalNormalizer::normalize($deviation->planned),
                'actual' => $deviation->actual === null ? null : DecimalNormalizer::normalize($deviation->actual),
                'unit' => $deviation->unit,
                'deviation_pct' => $deviation->deviation_pct,
                'threshold_pct' => $deviation->threshold_pct === null ? null : DecimalNormalizer::normalize($deviation->threshold_pct),
                'verdict' => $deviation->verdict->value,
                'requires_approval' => $deviation->requires_approval,
                'approval_status' => $deviation->approval_status->value,
                'reason' => $deviation->reason,
                'recorded_by_name' => $deviation->recorded_by_name,
                'recorded_at' => $deviation->recorded_at->toIso8601String(),
                'decided_by_name' => $deviation->decided_by_name,
                'decided_at' => $deviation->decided_at?->toIso8601String(),
                'decision_note' => $deviation->decision_note,
                'decide_url' => route('production.deviations.decide', $deviation, false),
            ])->all(),
            'blockers' => $batch->isOpen() ? $this->evaluator->completionBlockers($batch, $deviations) : [],
            'waste' => $this->waste->forBatch($batch, $sheet['sheet']['batchMassG'] ?? null),
            'alkali' => $this->alkali->forBatch($batch, $superfatPct),
            'lots' => $this->trace->lotsOfBatch($batch->getKey()),
            'threshold' => config('production.deviation_threshold_pct'),
            'approval' => $this->audience->approvalAvailability($user),
            'audience' => ['sees_prices' => $seesPrices],
            'choices' => [
                'kinds' => DeviationKind::options(),
                'units' => array_map(fn (string $unit): array => ['value' => $unit, 'label' => $unit], config('production.deviation_units')),
                'stepKinds' => ProcessStepKind::options(),
            ],
            'urls' => [
                'order' => route('production.orders.show', $batch->order, false),
                'weigh' => route('production.batches.lines.weigh', ['batch' => $batch, 'line' => '__LINE__'], false),
                'checks' => route('production.batches.checks.store', $batch, false),
                'deviations' => route('production.batches.deviations.store', $batch, false),
                'results' => route('production.batches.results.update', $batch, false),
                'complete' => route('production.batches.complete', $batch, false),
                'self' => route('production.batches.show', $batch, false),
                'trace' => route('production.trace', [], false),
            ],
        ];

        if ($seesPrices) {
            $props['cost'] = $this->cost->forBatch($batch);
        }

        return $props;
    }

    /** @return array<string, mixed> */
    private function reading(ProductionBatchCheck $check): array
    {
        return [
            'value' => DecimalNormalizer::normalize($check->value),
            'status' => $check->status,
            'measured_by' => $check->measured_by,
            'measured_at' => $check->measured_at->toIso8601String(),
            'note' => $check->note,
        ];
    }

    /** @return list<string> */
    private function recordedOf(ProductionBatch $batch): array
    {
        return $batch->deviations->map(fn (ProductionDeviation $deviation): string => $deviation->kind->value.'|'.$deviation->subject)->all();
    }
}
