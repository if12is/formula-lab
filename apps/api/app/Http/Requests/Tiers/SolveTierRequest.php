<?php

namespace App\Http\Requests\Tiers;

use App\Enums\ProductTier;
use App\Http\Requests\Concerns\DecimalRules;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the screen asks the optimizer: the product and tier (their saved profile is used), the material that balances the formula to 100 %,
 * the price change to try on each material of the answer, and the commercial inputs of the reverse pricing (typed what-ifs, empty = not given).
 */
class SolveTierRequest extends FormRequest
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
            'tier' => ['required', Rule::enum(ProductTier::class)],
            'qs_material_id' => ['nullable', 'uuid', Rule::exists('trade_materials', 'id')],
            'scenario_pct' => ['nullable', 'string', 'regex:/^-?\d{1,6}(\.\d{1,6})?$/'],
            'reverse' => ['nullable', 'array'],
            'reverse.margin_basis' => ['nullable', Rule::in(['markup_on_cost', 'margin_on_price'])],
            'reverse.factory_margin_pct' => $this->decimal(),
            'reverse.distribution_cost_per_unit' => $this->decimal(),
            'reverse.wholesaler_margin_pct' => $this->decimal(),
            'reverse.retailer_margin_pct' => $this->decimal(),
            'reverse.non_formula_cost_per_unit' => $this->decimal(),
            'reverse.pack_fill_kg' => $this->decimal(),
        ];
    }

    public function tier(): ProductTier
    {
        return ProductTier::from((string) $this->validated('tier'));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('tiers.attributes')];
    }
}
