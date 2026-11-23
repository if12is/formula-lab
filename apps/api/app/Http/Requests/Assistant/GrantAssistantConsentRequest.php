<?php

namespace App\Http\Requests\Assistant;

use App\Enums\Assistant\AssistantDataCategory;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantAssistantConsentRequest extends FormRequest
{
    use TranslatesValidationMessages;

    /** Open until accounts exist (U-01): the consent belongs to the browser session. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['required', 'string', Rule::enum(AssistantDataCategory::class)],
        ];
    }

    /** @return list<AssistantDataCategory> */
    public function categories(): array
    {
        return array_map(fn (string $value): AssistantDataCategory => AssistantDataCategory::from($value), $this->validated('categories'));
    }
}
