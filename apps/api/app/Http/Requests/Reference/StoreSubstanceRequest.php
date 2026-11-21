<?php

namespace App\Http\Requests\Reference;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubstanceRequest extends FormRequest
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
            'inci' => ['nullable', 'string', 'max:'.config('formula.max_text_length')],
            'cas' => ['nullable', 'string', 'regex:/^\d{2,7}-\d{2}-\d$/', Rule::unique('substances', 'cas')],
            'name_en' => ['nullable', 'string', 'max:'.config('formula.max_text_length')],
            'name_ar' => ['nullable', 'string', 'max:'.config('formula.max_text_length')],
            'mw' => ['nullable', 'string', 'regex:/^\d+(\.\d+)?$/'],
            'formula' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }

            if (! $this->filled('inci') && ! $this->filled('cas') && ! $this->filled('name_en') && ! $this->filled('name_ar')) {
                $validator->errors()->add('inci', __('materials.errors.substance_identity_required'));
            }
        });
    }
}
