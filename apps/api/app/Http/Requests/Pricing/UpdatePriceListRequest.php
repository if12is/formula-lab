<?php

namespace App\Http\Requests\Pricing;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Renames a price list, or switches it on or off. Only what is sent changes. */
class UpdatePriceListRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var PriceList $list */
        $list = $this->route('priceList');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('price_lists', 'name')->where('tenant_id', $list->tenant_id)->ignore($list->getKey())],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
