<?php

namespace App\Http\Requests\Production;

use App\Enums\Production\DeviationKind;
use App\Http\Requests\Concerns\ProductionDecimals;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A deviation from the plan: what it concerns, the two numbers when there are any, why, and who records it. */
class StoreProductionDeviationRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::enum(DeviationKind::class)],
            'subject' => ['required', 'string', 'max:'.config('production.max_text_length')],
            'planned' => $this->decimalRules(required: false),
            'actual' => $this->decimalRules(required: false),
            'unit' => ['nullable', 'string', Rule::in(config('production.deviation_units'))],
            'reason' => ['required', 'string', 'max:'.config('production.max_note_length')],
            'recorded_by_name' => ['required', 'string', 'max:'.config('production.max_text_length')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings('subject', 'planned', 'actual', 'reason', 'recorded_by_name');
    }
}
