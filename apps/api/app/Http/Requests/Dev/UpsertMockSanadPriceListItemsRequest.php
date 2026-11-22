<?php

namespace App\Http\Requests\Dev;

use App\Support\Dev\MockSanadPriceListItems;
use Illuminate\Validation\Validator;

/** The body of PUT price-lists/{uuid}/items (contract 8.3): `items` required, 1-500 rows. */
class UpsertMockSanadPriceListItemsRequest extends MockSanadRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            ...MockSanadPriceListItems::rules('items'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => MockSanadPriceListItems::check($validator, (array) $this->input('items', []), 'items'));
    }
}
