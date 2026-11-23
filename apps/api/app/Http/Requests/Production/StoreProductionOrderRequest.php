<?php

namespace App\Http\Requests\Production;

use App\Http\Requests\Concerns\ProductionDecimals;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\FormulaVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new production order: the approved formula version, the batch (size, unit, balance resolution), the units to make and the packaging. */
class StoreProductionOrderRequest extends FormRequest
{
    use ProductionDecimals, TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'formula_version_id' => ['required', 'uuid', 'exists:formula_versions,id'],
            'batch_size' => $this->decimalRules(positive: true),
            'batch_unit' => ['required', 'string', Rule::in(config('production.batch_units'))],
            'scale_resolution_g' => ['required', 'string', Rule::in(config('production.scale_resolutions_g'))],
            'units_count' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/', 'not_regex:'.self::NOT_ZERO],
            'planned_on' => ['required', 'date_format:Y-m-d'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_on'],
            'branch_code' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:'.config('production.max_note_length')],
            'packaging' => ['nullable', 'array', 'max:'.config('production.max_packaging_lines')],
            'packaging.*.trade_material_id' => ['required', 'uuid', 'distinct', Rule::exists('trade_materials', 'id')->where(fn ($query) => $query->where('item_kind', '<>', 'raw_material'))],
            'packaging.*.quantity' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/', 'not_regex:'.self::NOT_ZERO],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings('batch_size', 'units_count', 'branch_code');
        $this->merge(['packaging' => array_values(array_filter((array) $this->input('packaging', []), fn (mixed $line): bool => is_array($line) && ($line['trade_material_id'] ?? '') !== ''))]);
    }

    public function formulaVersion(): FormulaVersion
    {
        return FormulaVersion::query()->findOrFail($this->validated('formula_version_id'));
    }

    /** @return array<string, mixed> */
    public function orderData(): array
    {
        $data = $this->safe()->except('formula_version_id');
        $data['packaging'] = array_map(fn (array $line): array => ['trade_material_id' => $line['trade_material_id'], 'quantity' => $line['quantity']], $data['packaging'] ?? []);

        return $data;
    }
}
