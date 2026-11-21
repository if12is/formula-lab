<?php

namespace App\Http\Requests\Reference;

use App\Enums\SourceKind;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSourceRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(SourceKind::class)->except(SourceKind::UnverifiedEntry)],
            'title' => ['required', 'string', 'max:'.config('formula.max_text_length')],
            'url' => ['nullable', 'string', 'max:2048', 'regex:/^https?:\/\/\S+$/i'],
            'license' => ['nullable', 'string', 'max:'.config('formula.max_text_length')],
            'retrieved_at' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
