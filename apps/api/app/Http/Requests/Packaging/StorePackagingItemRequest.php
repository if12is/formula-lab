<?php

namespace App\Http\Requests\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Enums\PackagingMaterial;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\SanadSupplier;
use App\Support\DecimalCompare;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A packaging component (H-01): kind, material, capacities, weight, neck finish, supplier, and the source of the spec. */
class StorePackagingItemRequest extends FormRequest
{
    use TranslatesValidationMessages;

    /** The numeric spec fields, positive decimal strings. */
    private const SPEC_NUMBERS = ['nominal_capacity_ml', 'brimful_capacity_ml', 'unit_weight_g'];

    /** Every spec field; any of them filled needs a source and a confidence. */
    private const SPEC_FIELDS = ['packaging_material', 'nominal_capacity_ml', 'brimful_capacity_ml', 'unit_weight_g', 'neck_finish'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $number = ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,4})?$/'];

        return [
            'trade_name' => ['required', 'string', 'max:'.config('formula.max_text_length')],
            'item_kind' => ['required', Rule::in(ItemKind::packagingValues())],
            'packaging_material' => ['nullable', Rule::enum(PackagingMaterial::class)],
            'nominal_capacity_ml' => $number,
            'brimful_capacity_ml' => $number,
            'unit_weight_g' => $number,
            'neck_finish' => ['nullable', 'string', 'max:100'],
            'sanad_supplier_uuid' => ['nullable', 'uuid', Rule::exists(SanadSupplier::class, 'uuid')],
            'packaging_source_id' => ['nullable', 'uuid', 'exists:sources,id'],
            'packaging_confidence' => ['nullable', Rule::enum(Confidence::class)],
            'status' => ['sometimes', 'in:draft,active'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasSpec = collect(self::SPEC_FIELDS)->contains(fn (string $field): bool => $this->filled($field));

            // A spec value is a sourced fact: no source and confidence, no value.
            if ($hasSpec) {
                foreach (['packaging_source_id', 'packaging_confidence'] as $field) {
                    if (! $this->filled($field)) {
                        $validator->errors()->add($field, __('packaging.errors.source_required'));
                    }
                }
            }

            foreach (self::SPEC_NUMBERS as $field) {
                if ($this->filled($field) && ! $validator->errors()->has($field) && DecimalCompare::compare((string) $this->input($field), '0') <= 0) {
                    $validator->errors()->add($field, __('packaging.errors.positive'));
                }
            }

            // A container cannot brim at less than its nominal capacity.
            if ($this->filled('nominal_capacity_ml') && $this->filled('brimful_capacity_ml') && ! $validator->errors()->hasAny(['nominal_capacity_ml', 'brimful_capacity_ml'])
                && DecimalCompare::compare((string) $this->input('brimful_capacity_ml'), (string) $this->input('nominal_capacity_ml')) < 0) {
                $validator->errors()->add('brimful_capacity_ml', __('packaging.errors.brimful_below_nominal'));
            }
        });
    }

    /**
     * What is saved: empty text becomes null so an untouched spec stays "not recorded".
     *
     * @return array<string, mixed>
     */
    public function packagingData(): array
    {
        $data = $this->validated();
        $text = fn (string $key): ?string => ($data[$key] ?? '') === '' ? null : $data[$key];

        return [
            'trade_name' => $data['trade_name'],
            'item_kind' => $data['item_kind'],
            'status' => $data['status'] ?? 'active',
            'packaging_material' => $text('packaging_material'),
            'nominal_capacity_ml' => $text('nominal_capacity_ml'),
            'brimful_capacity_ml' => $text('brimful_capacity_ml'),
            'unit_weight_g' => $text('unit_weight_g'),
            'neck_finish' => $text('neck_finish'),
            'sanad_supplier_uuid' => $text('sanad_supplier_uuid'),
            'packaging_source_id' => $text('packaging_source_id'),
            'packaging_confidence' => $text('packaging_confidence'),
        ];
    }
}
