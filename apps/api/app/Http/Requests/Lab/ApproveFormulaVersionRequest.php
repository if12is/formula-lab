<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** The signature of an approval or of sending a version to the lab: an optional comment. */
class ApproveFormulaVersionRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['comment' => ['nullable', 'string', 'max:'.config('formula.max_reason_length')]];
    }
}
