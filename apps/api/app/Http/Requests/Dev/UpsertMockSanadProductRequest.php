<?php

namespace App\Http\Requests\Dev;

use App\Enums\Sanad\SanadItemKind;
use Illuminate\Validation\Rule;

class UpsertMockSanadProductRequest extends MockSanadRequest
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
            'name_en' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'item_kind' => ['required', Rule::enum(SanadItemKind::class)],
            'is_sellable' => ['nullable', 'boolean'],
            'uom' => ['nullable', 'string', 'max:100'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sell_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'guide_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'external_id' => ['nullable', 'string', 'max:80'],
        ];
    }
}
