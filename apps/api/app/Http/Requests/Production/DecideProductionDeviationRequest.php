<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** The approval or rejection of a deviation above the threshold: the note says why. */
class DecideProductionDeviationRequest extends FormRequest
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
            'decision' => ['required', 'string', 'in:approve,reject'],
            'note' => ['required', 'string', 'max:'.config('production.max_note_length')],
        ];
    }

    public function approves(): bool
    {
        return $this->validated('decision') === 'approve';
    }
}
