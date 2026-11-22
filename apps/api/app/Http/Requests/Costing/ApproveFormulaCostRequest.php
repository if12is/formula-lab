<?php

namespace App\Http\Requests\Costing;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** Approving the cost of a product: an optional comment kept with the approval. */
class ApproveFormulaCostRequest extends FormRequest
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

    public function comment(): ?string
    {
        $comment = trim((string) $this->validated('comment'));

        return $comment === '' ? null : $comment;
    }
}
