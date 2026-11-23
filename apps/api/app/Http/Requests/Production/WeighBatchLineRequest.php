<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\Concerns\ProductionDecimals;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** What was weighed (or counted) for one line, the lot it came from, what that lot cost if typed, and who weighed it. */
class WeighBatchLineRequest extends FormRequest
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
            'actual_quantity' => $this->decimalRules(),
            'lot_number' => ['nullable', 'string', 'max:'.config('production.max_text_length')],
            'unit_cost' => $this->decimalRules(required: false),
            'weighed_by' => ['required', 'string', 'max:'.config('production.max_text_length')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings('actual_quantity', 'lot_number', 'unit_cost', 'weighed_by');
    }
}
