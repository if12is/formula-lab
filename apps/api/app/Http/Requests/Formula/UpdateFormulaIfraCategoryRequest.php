<?php

namespace App\Http\Requests\Formula;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFormulaIfraCategoryRequest extends FormRequest
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
            // Free text on purpose: category names come from the IFRA certificate, the app keeps no list of them.
            'ifra_category' => ['nullable', 'string', 'max:50'],
        ];
    }
}
