<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\Concerns\ProductionDecimals;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** Starting the batch of an order: who makes it and, if known, the waste planned (% of the charged mass). */
class StartProductionBatchRequest extends FormRequest
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
            'operator' => ['required', 'string', 'max:'.config('production.max_text_length')],
            'planned_waste_pct' => ['nullable', 'string', 'regex:'.self::DECIMAL, 'lt:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings('operator', 'planned_waste_pct');
    }
}
