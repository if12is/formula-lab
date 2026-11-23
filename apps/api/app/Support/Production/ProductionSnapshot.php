<?php

namespace App\Support\Production;

use App\Models\FormulaProcessCheck;
use App\Models\FormulaProcessStep;
use App\Models\FormulaVersion;
use App\Support\DecimalNormalizer;

/**
 * The snapshot of an approved formula version kept on a production order and copied to its batch (Q-01, Q-11): what is made,
 * in what order, with which checks. It is built once, from the version, and only read afterwards, so a later change of a
 * material name or a new formula version never alters an order. The version's hash is kept beside it.
 */
final class ProductionSnapshot
{
    /**
     * @param  list<array{trade_material_id: string, material: string, quantity: string}>  $packaging
     * @return array<string, mixed>
     */
    public static function build(FormulaVersion $version, array $packaging): array
    {
        $version->load(['formula', 'lines.tradeMaterial', 'processSteps.checks', 'processSteps.formulaLine']);

        return [
            'formula' => ['id' => $version->formula_id, 'code' => $version->formula->code, 'name' => $version->formula->name, 'version' => $version->version, 'hash' => $version->hash],
            'lines' => $version->lines->map(fn ($line): array => [
                'line_no' => (string) $line->line_no,
                'trade_material_id' => $line->trade_material_id,
                'material' => $line->tradeMaterial->trade_name,
                'percent_ww' => $line->percent_ww === null ? null : DecimalNormalizer::normalize($line->percent_ww),
                'is_qs' => $line->is_qs,
                'phase' => $line->phase,
                'addition_temp_c' => $line->addition_temp_c === null ? null : DecimalNormalizer::normalize($line->addition_temp_c),
            ])->all(),
            'steps' => $version->processSteps->map(fn (FormulaProcessStep $step): array => [
                'step_no' => (string) $step->step_no,
                'kind' => $step->kind->value,
                'phase' => $step->phase,
                'line_no' => $step->formulaLine === null ? null : (string) $step->formulaLine->line_no,
                'temperature_c' => self::plain($step->temperature_c),
                'mixing_speed_rpm' => self::plain($step->mixing_speed_rpm),
                'duration_minutes' => self::plain($step->duration_minutes),
                'note' => $step->note,
                'checks' => $step->checks->map(fn (FormulaProcessCheck $check): array => [
                    'check_no' => (string) $check->check_no,
                    'property' => $check->property,
                    'unit' => $check->unit,
                    'limit_min' => self::plain($check->limit_min),
                    'limit_max' => self::plain($check->limit_max),
                    'source_id' => $check->source_id,
                    'confidence' => $check->confidence?->value,
                ])->all(),
            ])->all(),
            'packaging' => $packaging,
        ];
    }

    /**
     * The engine's weighing-sheet input for a snapshot: the lines with the order the process steps add them in.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public static function weighingLines(array $snapshot): array
    {
        $order = [];
        foreach ($snapshot['steps'] as $step) {
            if ($step['kind'] === 'add' && $step['line_no'] !== null && ! isset($order[$step['line_no']])) {
                $order[$step['line_no']] = (int) $step['step_no'];
            }
        }

        return array_map(fn (array $line): array => array_filter([
            'lineNo' => $line['line_no'],
            'materialId' => $line['trade_material_id'],
            'percentWW' => $line['percent_ww'],
            'qs' => $line['is_qs'] ?: null,
            'phase' => $line['phase'],
            'additionTempC' => $line['addition_temp_c'],
            'additionOrder' => $order[$line['line_no']] ?? null,
        ], fn (mixed $value): bool => $value !== null), $snapshot['lines']);
    }

    private static function plain(?string $value): ?string
    {
        return $value === null ? null : DecimalNormalizer::normalize($value);
    }
}
