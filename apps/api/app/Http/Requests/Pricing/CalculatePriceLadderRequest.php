<?php

namespace App\Http\Requests\Pricing;

use App\Http\Requests\Concerns\DecimalRules;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the price ladder screen asks the engine: the product, the margins and costs between the factory and the shelf (typed what-ifs; empty means
 * "not given" and the engine lists it as missing), an optional shelf price to walk back from, and the dollar moves to try.
 */
class CalculatePriceLadderRequest extends FormRequest
{
    use DecimalRules, TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'formula_id' => ['required', 'uuid', Rule::exists('formulas', 'id')],
            'margin_basis' => ['nullable', Rule::in(['markup_on_cost', 'margin_on_price'])],
            'factory_margin_pct' => $this->decimal(),
            'distribution_cost_per_unit' => $this->decimal(),
            'wholesaler_margin_pct' => $this->decimal(),
            'retailer_margin_pct' => $this->decimal(),
            'returns_pct' => $this->decimal(),
            'promo_pct' => $this->decimal(),
            'shelf_price' => $this->decimal(),
            'fill_kg' => $this->decimal(),
            'non_formula_cost_per_unit' => $this->decimal(),
            'fx_changes_pct' => ['nullable', 'array', 'max:'.config('pricing.ladder_max_fx_changes')],
            'fx_changes_pct.*' => ['nullable', 'string', 'regex:/^-?\d{1,6}(\.\d{1,6})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('price_ladder.attributes')];
    }
}
