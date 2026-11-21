<?php

namespace App\Services\Formula;

use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;

/** Version history of a formula and the difference between two of its versions. */
final class FormulaHistoryService
{
    public function __construct(
        private FormulaDiffService $formulaDiffService,
    ) {}

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Formula $formula, ?FormulaVersion $current = null): array
    {
        return $formula->versions()->reorder()->orderByDesc('version')->withCount('lines')->with('parent:id,version')->get()
            ->map(fn (FormulaVersion $version): array => [
                'number' => $version->version,
                'status' => $version->status->value,
                'reason' => $version->reason,
                'created_at' => $version->created_at?->toIso8601String(),
                'hash' => substr($version->hash, 0, 12),
                'lines_count' => $version->lines_count,
                'parent' => $version->parent?->version,
                'is_current' => $current !== null && $current->is($version),
            ])
            ->all();
    }

    /**
     * The difference from one version to another with materials shown by name.
     *
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>, meta: array<string, array{from: mixed, to: mixed}>, identical: bool}
     */
    public function compare(FormulaVersion $from, FormulaVersion $to): array
    {
        $diff = $this->formulaDiffService->diff($from, $to);

        $ids = collect([...$diff['added'], ...$diff['removed']])->pluck('tradeMaterialId')
            ->merge(array_column($diff['changed'], 'trade_material_id'))
            ->unique()
            ->all();
        $names = TradeMaterial::query()->whereIn('id', $ids)->pluck('trade_name', 'id');
        $name = fn (string $id): string => (string) ($names[$id] ?? $id);

        $line = fn (array $canonical): array => [
            'material_id' => $canonical['tradeMaterialId'],
            'material' => $name($canonical['tradeMaterialId']),
            'percent_ww' => $canonical['percentWw'],
            'is_qs' => $canonical['isQs'],
            'phase' => $canonical['phase'],
        ];

        $added = array_map($line, $diff['added']);
        $removed = array_map($line, $diff['removed']);
        $changed = array_map(fn (array $change): array => [
            'material_id' => $change['trade_material_id'],
            'material' => $name($change['trade_material_id']),
            'changes' => $change['changes'],
        ], $diff['changed']);

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'meta' => $diff['meta'],
            'identical' => $added === [] && $removed === [] && $changed === [] && $diff['meta'] === [],
        ];
    }
}
