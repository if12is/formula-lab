<?php

namespace App\Http\Requests\Decisions;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** Overriding a blocked publish decision (Y-04): the written reason, the password again (the e-signature) and the price the decision judges. */
class OverrideFinishedGoodDecisionRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return $this->user()?->can('decisions.override') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.config('decisions.override_reason_max_length')],
            'password' => ['required', 'string'],
            'sell_price' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }

    /** @return array{sell_price: string|null} The details the blocked decision was judged on. */
    public function details(): array
    {
        $price = (string) ($this->validated('sell_price') ?? '');

        return ['sell_price' => $price === '' ? null : $price];
    }
}
