<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\Concerns\ProductionDecimals;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** The end-of-batch figures: waste (planned and actual), units made and the conversion cost really spent. */
class UpdateBatchResultsRequest extends FormRequest
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
            'planned_waste_pct' => ['nullable', 'string', 'regex:'.self::DECIMAL, 'lt:100'],
            'actual_waste_g' => $this->decimalRules(required: false),
            'actual_units' => ['nullable', 'string', 'regex:'.self::DECIMAL, 'not_regex:'.self::NOT_ZERO],
            'actual_conversion_egp' => $this->decimalRules(required: false),
            'conversion_note' => ['nullable', 'string', 'max:'.config('production.max_note_length')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings('planned_waste_pct', 'actual_waste_g', 'actual_units', 'actual_conversion_egp');
    }
}
