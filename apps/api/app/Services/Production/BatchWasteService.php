<?php

namespace App\Services\Production;

use App\Models\ProductionBatch;
use App\Support\Engine\ProductionEngine;

/** Q-04, reporting side: the waste recorded on the batch against the planned waste, by the engine. The stock effect of waste is Sanad's. */
final class BatchWasteService
{
    public function __construct(private readonly ProductionEngine $engine) {}

    /**
     * @return array{state: string, result: array<string, mixed>|null, missing: list<string>}
     *                                                                                        state: `not_recorded` (no planned waste or no actual waste yet), `incomplete` (the engine cannot compare) or `compared`
     */
    public function forBatch(ProductionBatch $batch, ?string $chargedMassG): array
    {
        if ($batch->planned_waste_pct === null || $batch->actual_waste_g === null || $chargedMassG === null) {
            return ['state' => 'not_recorded', 'result' => null, 'missing' => []];
        }

        $answer = $this->engine->waste([
            'chargedMassG' => $chargedMassG,
            'plannedWastePct' => $batch->planned_waste_pct,
            'actualWasteG' => $batch->actual_waste_g,
            'thresholdPct' => config('production.deviation_threshold_pct'),
        ]);
        if (! $answer['ok']) {
            return ['state' => 'incomplete', 'result' => null, 'missing' => array_map(function (array $gap): string {
                $key = 'engine.'.$gap['reason'];

                return __($key) === $key ? (string) $gap['reason'] : __($key);
            }, $answer['missing'] ?? [])];
        }

        return ['state' => 'compared', 'result' => $answer['value'], 'missing' => []];
    }
}
