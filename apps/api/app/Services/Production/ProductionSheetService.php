<?php

namespace App\Services\Production;

use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Support\Engine\ProductionEngine;
use App\Support\Production\ProductionSnapshot;

/**
 * The weighing sheet of an order (Q-02) as the screen and the print view read it: the engine's sheet with the material names of the
 * snapshot attached. It holds weights only: no price, no cost, whoever looks at it.
 */
final class ProductionSheetService
{
    public function __construct(private readonly ProductionEngine $engine) {}

    /**
     * @return array{ok: bool, sheet: array<string, mixed>|null, missing: list<string>, warnings: list<string>}
     */
    public function forOrder(ProductionOrder|ProductionBatch $source): array
    {
        $order = $source instanceof ProductionBatch ? $source->order : $source;
        $names = array_column($order->snapshot['lines'], 'material', 'trade_material_id');

        $answer = $this->engine->weighingSheet([
            'lines' => ProductionSnapshot::weighingLines($order->snapshot),
            'batchMass' => ['value' => $order->batch_size, 'unit' => $order->batch_unit],
            'resolutionG' => $order->scale_resolution_g,
        ]);

        if (! $answer['ok']) {
            return [
                'ok' => false,
                'sheet' => null,
                'missing' => array_map(fn (array $gap): string => $this->reason((string) $gap['reason']), $answer['missing'] ?? []),
                'warnings' => [],
            ];
        }

        $sheet = $answer['value'];
        $sheet['lines'] = array_map(fn (array $line): array => [...$line, 'material' => $names[$line['materialId']] ?? $line['materialId']], $sheet['lines']);

        return ['ok' => true, 'sheet' => $sheet, 'missing' => [], 'warnings' => $answer['warnings']];
    }

    private function reason(string $reason): string
    {
        $key = 'engine.'.$reason;

        return __($key) === $key ? $reason : __($key);
    }
}
