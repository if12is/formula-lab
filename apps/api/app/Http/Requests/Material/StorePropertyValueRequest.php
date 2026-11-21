<?php

namespace App\Http\Requests\Material;

use App\Enums\Confidence;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\DecimalCompare;
use App\Support\PropertyRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePropertyValueRequest extends FormRequest
{
    use TranslatesValidationMessages;

    private const DECIMAL = '/^-?\d+(\.\d+)?$/';

    public function authorize(): bool
    {
        return true;
    }

    /** Only an IFRA limit has a category; for any other property the field is dropped. */
    protected function prepareForValidation(): void
    {
        if ($this->input('property') !== PropertyRegistry::IFRA_LIMIT) {
            $this->merge(['category' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'property' => ['required', 'string', Rule::in(PropertyRegistry::keys())],
            'value' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'min' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'max' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'unit' => ['required', 'string', Rule::in(PropertyRegistry::unitsFor((string) $this->input('property')))],
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'confidence' => ['required', Rule::enum(Confidence::class)],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'lot_no' => ['nullable', 'string', 'max:100'],
            // The IFRA category as the certificate names it (free text: no built-in list of categories).
            'category' => [Rule::requiredIf(fn (): bool => $this->input('property') === PropertyRegistry::IFRA_LIMIT), 'nullable', 'string', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $value = $this->input('value');
            $min = $this->input('min');
            $max = $this->input('max');

            if ($validator->errors()->hasAny(['value', 'min', 'max'])) {
                return;
            }

            if ($value === null && $min === null && $max === null) {
                $validator->errors()->add('value', __('materials.errors.value_or_range_required'));
            } elseif ($value !== null && ($min !== null || $max !== null)) {
                $validator->errors()->add('value', __('materials.errors.value_or_range_exclusive'));
            } elseif ($min !== null && $max !== null && DecimalCompare::compare($min, $max) > 0) {
                $validator->errors()->add('min', __('materials.errors.range_order'));
            }
        });
    }
}
