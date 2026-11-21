<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** The signature of a rejection: the reason is required. */
class RejectFormulaVersionRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'regex:/\S/u', 'max:'.config('formula.max_reason_length')]];
    }
}
