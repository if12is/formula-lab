<?php

namespace App\Http\Requests\Material;

use App\Enums\Confidence;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\IfraCertificate;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use App\Support\DecimalCompare;
use App\Support\PropertyRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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

    /**
     * Only an IFRA limit has a category and a certificate; for any other property both are dropped. An IFRA limit takes its
     * source, confidence and date from the certificate it is read from, so the limit can never say more than the document does.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('property') !== PropertyRegistry::IFRA_LIMIT) {
            $this->merge(['category' => null, 'ifra_certificate_id' => null]);

            return;
        }

        $certificate = $this->certificate();

        if ($certificate !== null) {
            $this->merge([
                'source_id' => $certificate->source_id,
                'confidence' => $certificate->confidence->value,
                'valid_from' => $certificate->issued_on->toDateString(),
            ]);
        }
    }

    private function certificate(): ?IfraCertificate
    {
        $material = $this->route('material');
        $id = $this->input('ifra_certificate_id');

        if (! $material instanceof TradeMaterial || ! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return IfraCertificate::query()->where('trade_material_id', $material->getKey())->find($id);
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
            // A limit is only valid data when it is read from a certificate of this very oil (one limit per category).
            'ifra_certificate_id' => [
                Rule::requiredIf(fn (): bool => $this->input('property') === PropertyRegistry::IFRA_LIMIT),
                'nullable',
                'uuid',
                Rule::exists('ifra_certificates', 'id')->where('trade_material_id', $this->route('material')?->getKey()),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $value = $this->input('value');
            $min = $this->input('min');
            $max = $this->input('max');

            if ($this->input('property') === PropertyRegistry::IFRA_LIMIT && $this->certificate() !== null
                && PropertyValue::query()->where('ifra_certificate_id', $this->input('ifra_certificate_id'))->where('category', $this->input('category'))->exists()) {
                $validator->errors()->add('category', __('materials.errors.ifra_category_taken'));
            }

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
