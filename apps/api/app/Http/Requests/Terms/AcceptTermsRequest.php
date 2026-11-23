<?php

namespace App\Http\Requests\Terms;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** Accepting the terms: the tick, and the version that was on screen (so a wording change since the page opened is caught). */
class AcceptTermsRequest extends FormRequest
{
    use TranslatesValidationMessages {
        messages as protected baseMessages;
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accepted' => ['required', 'accepted'],
            'version' => ['required', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...$this->baseMessages(), 'accepted' => __('terms.must_accept')];
    }

    public function version(): string
    {
        return (string) $this->validated('version');
    }
}
