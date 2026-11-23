<?php

namespace App\Http\Requests\Coa;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\CoaExtraction;
use App\Support\PropertyRegistry;
use App\Support\PropertyValueShape;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The human decision on a COA extraction: for every extracted field, accept (with the values the person confirms or corrects) or reject, and
 * the lot and date the accepted values are written under. Accepted values are checked like any typed property value: a property of the
 * registry, one of its units, decimal text, a value or an ordered range.
 */
class ConfirmCoaExtractionRequest extends FormRequest
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
            'lot_no' => ['required', 'string', 'max:100'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'supplier' => ['nullable', 'string', 'max:200'],
            'fields' => ['required', 'array', 'min:1', 'max:'.(int) config('coa.max_fields')],
            'fields.*.id' => ['required', 'uuid'],
            'fields.*.decision' => ['required', Rule::in(['accept', 'reject'])],
            'fields.*.property' => ['nullable', 'string'],
            'fields.*.value' => ['nullable', 'string', 'regex:'.PropertyValueShape::DECIMAL],
            'fields.*.min' => ['nullable', 'string', 'regex:'.PropertyValueShape::DECIMAL],
            'fields.*.max' => ['nullable', 'string', 'regex:'.PropertyValueShape::DECIMAL],
            'fields.*.unit' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $extraction = $this->route('extraction');
            $fields = (array) $this->input('fields', []);
            if (! $extraction instanceof CoaExtraction || $validator->errors()->has('fields') || $fields === []) {
                return;
            }

            // Every extracted field is decided, once; a field of another extraction is refused.
            $expected = $extraction->fields()->pluck('id')->all();
            $sent = array_column($fields, 'id');
            if (count($sent) !== count(array_unique($sent)) || array_diff($sent, $expected) !== [] || array_diff($expected, $sent) !== []) {
                $validator->errors()->add('fields', __('coa.validation.every_field_decided'));

                return;
            }

            foreach ($fields as $index => $field) {
                if (($field['decision'] ?? null) === 'accept') {
                    $this->checkAccepted($validator, $index, $field);
                }
            }
        });
    }

    /** @param  array<string, mixed>  $field */
    private function checkAccepted(Validator $validator, int|string $index, array $field): void
    {
        $property = $field['property'] ?? null;
        if (! is_string($property) || ! in_array($property, PropertyRegistry::keys(), true) || in_array($property, (array) config('coa.excluded_properties'), true)) {
            $validator->errors()->add("fields.{$index}.property", __('coa.validation.property_invalid'));

            return;
        }
        if (! in_array($field['unit'] ?? null, PropertyRegistry::unitsFor($property), true)) {
            $validator->errors()->add("fields.{$index}.unit", __('coa.validation.unit_invalid'));
        }
        if ($validator->errors()->hasAny(["fields.{$index}.value", "fields.{$index}.min", "fields.{$index}.max"])) {
            return;
        }

        $violation = PropertyValueShape::violation($field['value'] ?? null, $field['min'] ?? null, $field['max'] ?? null);
        if ($violation !== null) {
            $validator->errors()->add("fields.{$index}.".($violation === 'materials.errors.range_order' ? 'min' : 'value'), __($violation));
        }
    }

    /**
     * @return array{lot_no: string, valid_from: string, supplier: string|null}
     */
    public function confirmation(): array
    {
        return $this->safe()->only(['lot_no', 'valid_from', 'supplier']) + ['supplier' => null];
    }

    /** @return list<array<string, mixed>> */
    public function decisions(): array
    {
        return array_values((array) $this->validated('fields'));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('coa.attributes')];
    }
}
