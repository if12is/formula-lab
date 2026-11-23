<?php

namespace App\Http\Requests\Formula;

use App\Enums\Confidence;
use App\Enums\FormulaPhase;
use App\Enums\Production\ProcessStepKind;
use App\Http\Requests\Concerns\ProductionDecimals;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The manufacturing steps of a draft version (Q-10): each step with its numbers and check points; a limit names its source. */
class UpdateFormulaProcessRequest extends FormRequest
{
    use ProductionDecimals, TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'steps' => ['present', 'array', 'max:'.config('production.max_steps')],
            'steps.*.kind' => ['required', 'string', Rule::enum(ProcessStepKind::class)],
            'steps.*.phase' => ['nullable', 'string', Rule::enum(FormulaPhase::class)],
            'steps.*.line_no' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],
            'steps.*.temperature_c' => $this->decimalRules(required: false),
            'steps.*.mixing_speed_rpm' => $this->decimalRules(required: false, positive: true),
            'steps.*.duration_minutes' => $this->decimalRules(required: false, positive: true),
            'steps.*.note' => ['nullable', 'string', 'max:'.config('production.max_note_length')],
            'steps.*.checks' => ['nullable', 'array', 'max:'.config('production.max_checks_per_step')],
            'steps.*.checks.*.property' => ['required', 'string', Rule::in(config('production.check_properties'))],
            'steps.*.checks.*.unit' => ['required', 'string', 'max:20'],
            'steps.*.checks.*.limit_min' => ['nullable', 'string', 'regex:'.self::SIGNED_DECIMAL],
            'steps.*.checks.*.limit_max' => ['nullable', 'string', 'regex:'.self::SIGNED_DECIMAL],
            'steps.*.checks.*.source_id' => ['nullable', 'uuid', 'exists:sources,id'],
            'steps.*.checks.*.confidence' => ['nullable', 'string', Rule::in(array_column(Confidence::cases(), 'value'))],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return array_values((array) $this->validated('steps'));
    }
}
