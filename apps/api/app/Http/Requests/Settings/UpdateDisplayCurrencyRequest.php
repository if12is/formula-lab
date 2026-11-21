<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDisplayCurrencyRequest extends FormRequest
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
            'currency' => ['required', 'string', Rule::in(Currencies::codes())],
        ];
    }
}
