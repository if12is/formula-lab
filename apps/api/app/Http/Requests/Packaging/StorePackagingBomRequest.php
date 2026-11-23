<?php

namespace App\Http\Requests\Packaging;

use App\Enums\Sanad\SanadItemKind;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\SanadProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The packaging BOM of a SKU (S-12), for adding and editing: its name, SKU code, Sanad finished good, packing configuration and note. */
class StorePackagingBomRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:'.config('formula.max_text_length')],
            'sku_code' => ['nullable', 'string', 'max:100'],
            'sanad_product_uuid' => ['nullable', 'uuid', Rule::exists(SanadProduct::class, 'uuid')->where('item_kind', SanadItemKind::FinishedGood->value)->where('deleted', false)],
            'units_per_carton' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'cartons_per_pallet' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:'.config('lab.max_note_length')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('packaging.bom.attributes')];
    }

    /**
     * What is saved: an empty field becomes null so an untouched packing figure stays "not recorded".
     *
     * @return array<string, mixed>
     */
    public function bomData(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'sku_code' => $data['sku_code'] ?? null,
            'sanad_product_uuid' => $data['sanad_product_uuid'] ?? null,
            'units_per_carton' => $data['units_per_carton'] ?? null,
            'cartons_per_pallet' => $data['cartons_per_pallet'] ?? null,
            'note' => $data['note'] ?? null,
        ];
    }
}
