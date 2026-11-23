<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\Concerns\ProductionDecimals;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** One reading at a check point of the batch's process. */
class StoreBatchCheckRequest extends FormRequest
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
            'step_no' => ['required', 'integer', 'min:1'],
            'check_no' => ['required', 'integer', 'min:1'],
            'value' => ['required', 'string', 'regex:'.self::SIGNED_DECIMAL],
            'measured_by' => ['required', 'string', 'max:'.config('production.max_text_length')],
            'measured_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:'.config('production.max_note_length')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings('value', 'measured_by');
    }
}
