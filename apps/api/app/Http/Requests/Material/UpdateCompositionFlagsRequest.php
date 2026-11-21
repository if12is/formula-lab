<?php

namespace App\Http\Requests\Material;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompositionFlagsRequest extends FormRequest
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
            'composition_complete' => ['sometimes', 'boolean'],
            'is_fragrance' => ['sometimes', 'boolean'],
        ];
    }
}
