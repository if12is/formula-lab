<?php

namespace App\Services\Packaging;

use App\Exceptions\Domain\EngineException;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use App\Support\DecimalNormalizer;
use App\Support\Engine\EngineRunner;
use App\Support\Engine\GapText;
use App\Support\Packaging\CompatPairsPayload;

/**
 * The cost preview of a packaging BOM: the packaging cost per selling unit, worked out by the engine (packagingCostPerUnit, H-03, with the
 * declared closure pairs of H-02). This class only loads the lines with their sources and words the answer for the screen; it adds,
 * divides and rounds nothing. A figure the BOM does not have comes back as a gap in `missing`, never as a zero.
 */
final class PackagingBomCostService
{
    /** The lang group that names what a gap is about (price, quantity, reject %, packing ...). */
    private const GAP_SUBJECTS = 'packaging.bom.gap_subjects';

    public function __construct(private EngineRunner $engine) {}

    /**
     * @return array{ok: bool, total: array<string, mixed>|null, lines: list<array<string, mixed>>, missing: list<array<string, mixed>>, warnings: list<string>, entities: array<string, array{label: string}>, sources: array<string, array{title: string}>}
     *
     * @throws EngineException When the engine cannot answer.
     */
    public function preview(PackagingBom $bom): array
    {
        $lines = $bom->lines()->with(['tradeMaterial', 'source'])->get();

        if ($lines->isEmpty()) {
            return ['ok' => false, 'total' => null, 'lines' => [], 'missing' => [], 'warnings' => [], 'entities' => [], 'sources' => []];
        }

        $answer = $this->engine->run('packaging-bom-cost', [
            'items' => $lines->map(fn (PackagingBomLine $line): array => $this->item($line))->all(),
            'packing' => array_filter([
                'unitsPerCarton' => $bom->units_per_carton === null ? null : (string) $bom->units_per_carton,
                'cartonsPerPallet' => $bom->cartons_per_pallet === null ? null : (string) $bom->cartons_per_pallet,
            ], fn (?string $value): bool => $value !== null),
            'pairs' => CompatPairsPayload::load(),
        ]);

        $names = $lines->mapWithKeys(fn (PackagingBomLine $line): array => [(string) $line->trade_material_id => $line->tradeMaterial->trade_name])->all();
        // What the explain panel needs to word the engine's trace: component names for their ids and source titles for the leaves.
        $explain = [
            'entities' => array_map(fn (string $name): array => ['label' => $name], $names),
            'sources' => $lines->filter(fn (PackagingBomLine $line): bool => $line->source !== null)
                ->mapWithKeys(fn (PackagingBomLine $line): array => [(string) $line->source_id => ['title' => $line->source->title]])->all(),
        ];

        if (($answer['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'total' => null,
                'lines' => [],
                'missing' => GapText::all(array_values($answer['missing'] ?? []), $names, self::GAP_SUBJECTS),
                'warnings' => array_values($answer['warnings'] ?? []),
                ...$explain,
            ];
        }

        $costed = collect($answer['value']['lines'])->keyBy('key');

        return [
            'ok' => true,
            'total' => $answer['value']['total'],
            'lines' => $lines->map(fn (PackagingBomLine $line): array => [
                'line_id' => $line->getKey(),
                'name' => $line->tradeMaterial->trade_name,
                'per_unit' => $costed->get('packaging:'.$line->trade_material_id)['perUnit'] ?? null,
            ])->all(),
            'missing' => [],
            'warnings' => array_values($answer['warnings'] ?? []),
            ...$explain,
        ];
    }

    /**
     * One line as the engine's PackagingBomItem. A figure not recorded is left out (the engine names it), and a recorded one carries its
     * source, confidence and the source's date.
     *
     * @return array<string, mixed>
     */
    private function item(PackagingBomLine $line): array
    {
        $material = $line->tradeMaterial;
        $sourced = fn (?string $value): ?array => $value === null ? null : [
            'value' => DecimalNormalizer::normalize($value),
            'sourceId' => (string) $line->source_id,
            'confidence' => $line->confidence?->value,
            'validFrom' => $line->source?->retrieved_at?->toDateString() ?? '',
        ];

        return array_filter([
            'itemId' => (string) $line->trade_material_id,
            'kind' => $material->item_kind->value,
            'level' => $line->level->value,
            'neckFinish' => $material->neck_finish,
            'price' => ($price = $sourced($line->price_egp)) === null ? null : [...$price, 'unit' => 'EGP'],
            'qtyPerUnit' => $sourced($line->qty_per_unit),
            'rejectPct' => $sourced($line->reject_pct),
        ], fn (mixed $part): bool => $part !== null);
    }
}
