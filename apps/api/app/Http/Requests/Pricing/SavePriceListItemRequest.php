<?php

namespace App\Http\Requests\Pricing;

use App\Enums\Confidence;
use App\Enums\PriceListMode;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\DecimalCompare;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A product line of a price list: the product (when adding) and how its price is decided. A rule line names a rule of
 * config/pricing.php; an override line carries the agreed price with the source and confidence it comes from.
 */
class SavePriceListItemRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $override = fn (): string => $this->input('mode') === PriceListMode::Override->value ? 'required' : 'prohibited';

        return [
            'formula_id' => [$this->route('item') === null ? 'required' : 'prohibited', 'uuid', 'exists:formulas,id'],
            'mode' => ['required', Rule::enum(PriceListMode::class)],
            'rule_key' => [$this->input('mode') === PriceListMode::Rule->value ? 'required' : 'prohibited', 'nullable', 'string', 'max:100'],
            'override_price' => [$override(), 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'override_source_id' => [$override(), 'nullable', 'uuid', 'exists:sources,id'],
            'override_confidence' => [$override(), 'nullable', Rule::enum(Confidence::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('override_price') && ! $validator->errors()->has('override_price') && DecimalCompare::compare((string) $this->input('override_price'), '0') <= 0) {
                $validator->errors()->add('override_price', __('price_lists.errors.price_positive'));
            }
        });
    }
}
