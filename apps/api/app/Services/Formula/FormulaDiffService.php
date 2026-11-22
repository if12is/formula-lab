<?php

namespace App\Services\Formula;

use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Support\DecimalNormalizer;

/**
 * Differences between two formula versions (lines, reason, status).
 * Out of scope for now: a "sources diff" (lines whose materials' chosen property sources
 * changed between the versions); it needs the value-selection rule of D-04.
 */
final class FormulaDiffService
{
    /**
     * Lines are matched by trade material (n-th occurrence of a material pairs with the n-th in the
     * other version). A swapped material therefore shows as one removed plus one added line.
     *
     * @return array{
     *     added: list<array<string, mixed>>,
     *     removed: list<array<string, mixed>>,
     *     changed: list<array{trade_material_id: string, changes: array<string, array{from: mixed, to: mixed}>}>,
     *     meta: array<string, array{from: mixed, to: mixed}>
     * }
     */
    public function diff(FormulaVersion $from, FormulaVersion $to): array
    {
        $before = $this->keyedLines($from);
        $after = $this->keyedLines($to);

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($after as $key => $line) {
            if (! isset($before[$key])) {
                $added[] = $line->canonical();

                continue;
            }
            $changes = $this->lineChanges($before[$key], $line);
            if ($changes !== []) {
                $changed[] = ['trade_material_id' => $line->trade_material_id, 'changes' => $changes];
            }
        }
        foreach ($before as $key => $line) {
            if (! isset($after[$key])) {
                $removed[] = $line->canonical();
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'meta' => array_filter([
                'reason' => $this->change($from->reason, $to->reason),
                'status' => $this->change($from->status->value, $to->status->value),
            ]),
        ];
    }

    /** @return array<string, FormulaLine> */
    private function keyedLines(FormulaVersion $version): array
    {
        $seen = [];
        $keyed = [];
        foreach ($version->lines()->get() as $line) {
            $occurrence = $seen[$line->trade_material_id] = ($seen[$line->trade_material_id] ?? 0) + 1;
            $keyed["{$line->trade_material_id}#{$occurrence}"] = $line;
        }

        return $keyed;
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function lineChanges(FormulaLine $a, FormulaLine $b): array
    {
        return array_filter([
            'percent_ww' => $this->change($this->percent($a), $this->percent($b)),
            'is_qs' => $this->change($a->is_qs, $b->is_qs),
            'phase' => $this->change($a->phase, $b->phase),
            'addition_temp_c' => $this->change($this->temperature($a), $this->temperature($b)),
        ]);
    }

    private function temperature(FormulaLine $line): ?string
    {
        return $line->addition_temp_c === null ? null : DecimalNormalizer::normalize($line->addition_temp_c);
    }

    private function percent(FormulaLine $line): ?string
    {
        return $line->percent_ww === null ? null : DecimalNormalizer::normalize($line->percent_ww);
    }

    /** @return array{from: mixed, to: mixed}|null */
    private function change(mixed $from, mixed $to): ?array
    {
        return $from === $to ? null : ['from' => $from, 'to' => $to];
    }
}
