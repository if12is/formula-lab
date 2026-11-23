<?php

namespace App\Services\Production;

use App\Enums\Production\BatchLineKind;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Services\Costing\CostApprovalService;
use App\Support\Engine\ProductionEngine;

/**
 * Q-09: the real cost of a batch, by the engine: the weighed materials at the cost of the lots they came from, the packaging used at
 * its real cost and the conversion cost really spent, per unit, against the computed (approved) unit cost. A line with no cost, or a
 * missing conversion or unit count, stops the calculation and is named. The cost of a lot is the invoice cost of its Sanad purchase line (or
 * a typed one): freight and duties are not on it, so this is not yet a full landed cost (orchestration/HUMAN-TODO.md).
 */
final class BatchCostService
{
    public function __construct(
        private readonly ProductionEngine $engine,
        private readonly CostApprovalService $approvals,
    ) {}

    /** @return array<string, mixed> */
    public function forBatch(ProductionBatch $batch): array
    {
        $batch->loadMissing(['lines.tradeMaterial', 'formulaVersion.formula']);
        $approval = $this->approvals->current($batch->formulaVersion->formula);

        $line = fn (ProductionBatchLine $row): array => [
            'id' => "{$row->line_no} · {$row->tradeMaterial->trade_name}",
            'quantity' => $row->actual_quantity,
            'quantityUnit' => $row->kind === BatchLineKind::RawMaterial ? 'g' : 'pc',
            'unitCost' => $row->unit_cost,
            'costUnit' => $row->kind === BatchLineKind::RawMaterial ? 'kg' : 'pc',
        ];

        $answer = $this->engine->actualCost([
            'materials' => $batch->lines->where('kind', BatchLineKind::RawMaterial)->map($line)->values()->all(),
            'packaging' => $batch->lines->where('kind', BatchLineKind::Packaging)->map($line)->values()->all(),
            'conversion' => $batch->actual_conversion_egp,
            'units' => $batch->actual_units,
            'computedUnitCost' => $approval?->unit_cost,
            'thresholdPct' => config('production.deviation_threshold_pct'),
        ]);

        return [
            'ok' => $answer['ok'],
            'result' => $answer['value'] ?? null,
            'missing' => $answer['ok'] ? [] : array_map(fn (array $gap): string => $this->gap($gap), $answer['missing'] ?? []),
            'computed' => $approval === null ? null : ['unit_cost' => $approval->unit_cost, 'approved_at' => $approval->approved_at->toIso8601String()],
        ];
    }

    /** @param  array<string, mixed>  $gap */
    private function gap(array $gap): string
    {
        $key = 'engine.'.$gap['reason'];
        $reason = __($key) === $key ? (string) $gap['reason'] : __($key);
        $property = (string) $gap['property'];
        $subject = isset($gap['materialId']) ? (string) $gap['materialId'] : (__("production.cost.properties.{$property}") === "production.cost.properties.{$property}" ? $property : __("production.cost.properties.{$property}"));

        return "{$subject}: {$reason}";
    }
}
