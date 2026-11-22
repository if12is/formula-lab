<?php

namespace App\Http\Requests\Formula;

use App\Enums\ProductTier;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Http\Requests\Formula\Concerns\ValidatesFormulaLines;
use App\Models\ProductFamily;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A new formula: its identity and the lines of its first version. */
class StoreFormulaRequest extends FormRequest
{
    use TranslatesValidationMessages, ValidatesFormulaLines;

    public function authorize(): bool
    {
        return true;
    }

    /** Choosing a registry family fills the product type with its code unless one was typed (compliance rules and lab requirements read it). */
    protected function prepareForValidation(): void
    {
        if (trim((string) $this->input('product_type')) === '' && $this->filled('product_family')) {
            $this->merge(['product_type' => $this->input('product_family')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = Tenant::query()->where('name', config('formula.default_tenant_name'))->value('id');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('formulas', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:'.config('formula.max_text_length')],
            'product_type' => ['required', 'string', 'max:100'],
            'product_family' => ['nullable', 'string', Rule::exists('product_families', 'code')],
            'tier' => ['nullable', Rule::enum(ProductTier::class)],
            ...$this->lineRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateLines($validator));
    }

    /** @return array{code: string, name: string, product_type: string, product_family_id: string|null, tier: string|null} */
    public function formulaData(): array
    {
        $data = $this->validated();

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'product_type' => $data['product_type'],
            'product_family_id' => isset($data['product_family']) ? ProductFamily::query()->where('code', $data['product_family'])->value('id') : null,
            'tier' => $data['tier'] ?? null,
        ];
    }
}
