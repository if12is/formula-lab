<?php

namespace App\Http\Requests\Dev;

use App\Support\Dev\MockSanadPriceListItems;
use Illuminate\Validation\Validator;

/**
 * The body of PUT price-lists/{uuid} (contract 8.2): name required, only EGP, up to 500 item rows. A price must be present: a
 * decimal string above zero upserts, null removes; a JSON number, "0", a negative or a non-decimal string is refused.
 */
class UpsertMockSanadPriceListRequest extends MockSanadRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['uuid' => (string) $this->route('uuid')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'in:EGP'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'max:500'],
            ...MockSanadPriceListItems::rules('items'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => MockSanadPriceListItems::check($validator, (array) $this->input('items', []), 'items'));
    }
}
