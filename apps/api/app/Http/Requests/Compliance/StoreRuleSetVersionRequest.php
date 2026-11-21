<?php

namespace App\Http\Requests\Compliance;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreRuleSetVersionRequest extends FormRequest
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
            'version' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+-]*$/'],
        ];
    }
}
