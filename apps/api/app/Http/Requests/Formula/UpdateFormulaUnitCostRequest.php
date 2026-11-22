<?php

namespace App\Http\Requests\Formula;

use App\Enums\Confidence;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\DecimalCompare;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The unit cost (EGP per selling unit) of a product, with its source and confidence. */
class UpdateFormulaUnitCostRequest extends FormRequest
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
            'unit_cost' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'confidence' => ['required', Rule::enum(Confidence::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->has('unit_cost') && DecimalCompare::compare((string) $this->input('unit_cost'), '0') <= 0) {
                $validator->errors()->add('unit_cost', __('price_lists.errors.unit_cost_positive'));
            }
        });
    }
}
