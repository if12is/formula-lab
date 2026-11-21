<?php

namespace App\Http\Requests\Material;

use App\Enums\Confidence;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['nullable', 'string', Rule::in(Currencies::codes())],
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'quoted_on' => ['required', 'date_format:Y-m-d'],
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'confidence' => ['required', Rule::enum(Confidence::class)],
        ];
    }
}
