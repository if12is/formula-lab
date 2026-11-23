<?php

namespace App\Http\Requests\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Enums\PackLevel;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use App\Models\TradeMaterial;
use App\Support\DecimalCompare;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A line of a packaging BOM (S-12), for adding and editing: the component, its level, and the quantity, reject % and piece price. Each
 * figure may be left empty (the cost preview then names the gap); a figure that is filled needs the source and confidence it came from.
 */
class StorePackagingBomLineRequest extends FormRequest
{
    use TranslatesValidationMessages;

    /** The figures of a line; any of them filled needs a source and a confidence. */
    private const FIGURES = ['qty_per_unit', 'reject_pct', 'price_egp'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bom = $this->route('bom');
        $line = $this->route('line');

        return [
            'trade_material_id' => [
                'required', 'uuid',
                Rule::exists(TradeMaterial::class, 'id')->where(fn ($query) => $query->where('item_kind', '<>', ItemKind::RawMaterial->value)),
                Rule::unique(PackagingBomLine::class, 'trade_material_id')
                    ->where('packaging_bom_id', $bom instanceof PackagingBom ? $bom->getKey() : null)
                    ->ignore($line instanceof PackagingBomLine ? $line->getKey() : null),
            ],
            'level' => ['required', Rule::enum(PackLevel::class)],
            'qty_per_unit' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,6})?$/'],
            'reject_pct' => ['nullable', 'string', 'regex:/^\d{1,2}(\.\d{1,6})?$/'],
            'price_egp' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,6})?$/'],
            'source_id' => ['nullable', 'uuid', 'exists:sources,id'],
            'confidence' => ['nullable', Rule::enum(Confidence::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (collect(self::FIGURES)->contains(fn (string $field): bool => $this->filled($field))) {
                foreach (['source_id', 'confidence'] as $field) {
                    if (! $this->filled($field)) {
                        $validator->errors()->add($field, __('packaging.bom.errors.source_required'));
                    }
                }
            }

            if ($this->filled('qty_per_unit') && ! $validator->errors()->has('qty_per_unit') && DecimalCompare::compare((string) $this->input('qty_per_unit'), '0') <= 0) {
                $validator->errors()->add('qty_per_unit', __('packaging.errors.positive'));
            }

            if ($this->filled('reject_pct') && ! $validator->errors()->has('reject_pct') && DecimalCompare::compare((string) $this->input('reject_pct'), '100') >= 0) {
                $validator->errors()->add('reject_pct', __('packaging.bom.errors.reject_below_100'));
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('packaging.bom.attributes')];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'trade_material_id.unique' => __('packaging.bom.errors.component_duplicate')];
    }

    /**
     * What is saved: an empty figure becomes null so an untouched one stays "not recorded".
     *
     * @return array<string, mixed>
     */
    public function lineData(): array
    {
        $data = $this->validated();
        $hasFigure = collect(self::FIGURES)->contains(fn (string $field): bool => ($data[$field] ?? null) !== null);

        return [
            'trade_material_id' => $data['trade_material_id'],
            'level' => $data['level'],
            'qty_per_unit' => $data['qty_per_unit'] ?? null,
            'reject_pct' => $data['reject_pct'] ?? null,
            'price_egp' => $data['price_egp'] ?? null,
            // A source with no figure to vouch for is not kept: the table ties them together.
            'source_id' => $hasFigure ? ($data['source_id'] ?? null) : null,
            'confidence' => $hasFigure ? ($data['confidence'] ?? null) : null,
        ];
    }
}
