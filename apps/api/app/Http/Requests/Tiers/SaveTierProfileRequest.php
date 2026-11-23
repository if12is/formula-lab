<?php

namespace App\Http\Requests\Tiers;

use App\Enums\ProductTier;
use App\Http\Requests\Concerns\DecimalRules;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The profile of one tier of the product's type, as typed: figures are decimals or empty (no constraint), and every figure written needs
 * the source it comes from. What the figures mean together (min above max, a material both required and forbidden) is the optimizer's
 * validator's to say when the screen solves; here a figure is only checked to be a number.
 */
class SaveTierProfileRequest extends FormRequest
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
            'total_active_min_pct' => $this->decimal(),
            'total_active_max_pct' => $this->decimal(),
            'max_cost_egp_per_kg' => $this->decimal(),
            'target_per' => ['required', Rule::in(['kg', 'pack'])],
            'target_value' => $this->decimal(),
            'forbidden' => ['nullable', 'array', 'max:'.config('tiers.max_listed_materials')],
            'forbidden.*' => ['uuid', Rule::exists('trade_materials', 'id')],
            'bounds' => ['nullable', 'array', 'max:'.config('tiers.max_listed_materials')],
            'bounds.*.material_id' => ['required', 'uuid', Rule::exists('trade_materials', 'id')],
            'bounds.*.min' => $this->decimal(),
            'bounds.*.max' => $this->decimal(),
            'bounds.*.required' => ['nullable', 'boolean'],
            'source_id' => ['nullable', 'uuid', Rule::exists('sources', 'id')],
        ];
    }

    /** A figure with no source cannot be kept: the source is what makes it a decision and not a guess (CLAUDE.md rule 2). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('source_id') || $validator->errors()->isNotEmpty()) {
                return;
            }
            $written = collect(['total_active_min_pct', 'total_active_max_pct', 'max_cost_egp_per_kg', 'target_value'])->contains(fn (string $field): bool => $this->filled($field))
                || ! empty($this->input('forbidden'))
                || collect($this->input('bounds', []))->contains(fn (array $bound): bool => ! empty($bound['required']) || ($bound['min'] ?? '') !== '' || ($bound['max'] ?? '') !== '');
            if ($written) {
                $validator->errors()->add('source_id', __('tiers.errors.source_required'));
            }
        });
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
