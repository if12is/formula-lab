<?php

namespace App\Http\Requests\Dev;

class UpdateMockSanadWholesalePricesRequest extends MockSanadRequest
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
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'guide_price' => ['nullable', 'numeric', 'min:0'],
            'sell_price' => ['nullable', 'numeric', 'min:0'],
            'tier' => ['nullable', 'string', 'max:100'],
        ];
    }
}
