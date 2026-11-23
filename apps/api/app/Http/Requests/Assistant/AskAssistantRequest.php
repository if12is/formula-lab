<?php

namespace App\Http\Requests\Assistant;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskAssistantRequest extends FormRequest
{
    use TranslatesValidationMessages;

    /** Open until accounts exist (U-01); the route is rate limited. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxChars = (int) config('assistant.max_message_chars');

        return [
            'question' => ['required', 'string', "max:{$maxChars}"],
            'history' => ['nullable', 'array', 'max:'.(int) config('assistant.max_history_messages')],
            'history.*' => ['array:role,text'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.text' => ['required', 'string', "max:{$maxChars}"],
        ];
    }

    /** @return list<array{role: string, text: string}> */
    public function history(): array
    {
        return array_values($this->validated('history') ?? []);
    }
}
