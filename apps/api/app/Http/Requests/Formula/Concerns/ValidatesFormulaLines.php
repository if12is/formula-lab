<?php

namespace App\Http\Requests\Formula\Concerns;

use App\Support\DecimalCompare;
use Illuminate\Validation\Validator;

/**
 * Rules for the lines of a formula version as the editor submits them. A draft may be saved in any state
 * (it need not add up to 100, materials may repeat): the engine reports those. What the database cannot
 * hold is refused here: a share outside 0-100, a line without a share (unless it is the q.s. line), two q.s. lines.
 */
trait ValidatesFormulaLines
{
    /** @return array<string, mixed> */
    protected function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.config('formula.max_lines')],
            'lines.*.trade_material_id' => ['required', 'uuid', 'exists:trade_materials,id'],
            'lines.*.percent_ww' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,9})?$/'],
            'lines.*.is_qs' => ['boolean'],
            'lines.*.phase' => ['nullable', 'string', 'max:100'],
            // Degrees Celsius, a plain decimal; whether it makes sense for the phase is the engine's check, not a database limit.
            'lines.*.addition_temp_c' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
        ];
    }

    protected function validateLines(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['lines', 'lines.*'])) {
            return;
        }

        $qsCount = 0;

        foreach ((array) $this->input('lines', []) as $index => $line) {
            $isQs = (bool) ($line['is_qs'] ?? false);
            $percent = $line['percent_ww'] ?? null;
            $qsCount += $isQs ? 1 : 0;

            if ($percent === null && ! $isQs) {
                $validator->errors()->add("lines.{$index}.percent_ww", __('formulas.errors.percent_required'));
            } elseif ($percent !== null && DecimalCompare::compare($percent, '100') > 0) {
                $validator->errors()->add("lines.{$index}.percent_ww", __('formulas.errors.percent_over_100'));
            }
        }

        if ($qsCount > 1) {
            $validator->errors()->add('lines', __('formulas.errors.single_qs'));
        }
    }

    /**
     * The validated lines in the shape of FormulaVersionService.
     *
     * @return list<array{trade_material_id: string, percent_ww: string|null, is_qs: bool, phase: string|null, addition_temp_c: string|null}>
     */
    public function lineData(): array
    {
        return array_map(fn (array $line): array => [
            'trade_material_id' => $line['trade_material_id'],
            'percent_ww' => $line['percent_ww'] ?? null,
            'is_qs' => (bool) ($line['is_qs'] ?? false),
            'phase' => $line['phase'] ?? null,
            'addition_temp_c' => $line['addition_temp_c'] ?? null,
        ], array_values($this->validated('lines')));
    }
}
