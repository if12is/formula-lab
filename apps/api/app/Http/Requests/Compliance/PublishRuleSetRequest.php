<?php

namespace App\Http\Requests\Compliance;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class PublishRuleSetRequest extends FormRequest
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
            // The hash the person saw in the confirmation; publishing refuses to go on when the rules no longer hash to it.
            'expected_hash' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }
}
