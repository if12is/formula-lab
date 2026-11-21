<?php

namespace App\Http\Requests\Formula;

use App\Enums\ProductTier;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Http\Requests\Formula\Concerns\ValidatesFormulaLines;
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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = Tenant::query()->where('name', config('formula.default_tenant_name'))->value('id');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('formulas', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:'.config('formula.max_text_length')],
            'product_type' => ['required', 'string', 'max:100'],
            'tier' => ['nullable', Rule::enum(ProductTier::class)],
            ...$this->lineRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateLines($validator));
    }

    /** @return array{code: string, name: string, product_type: string, tier: string|null} */
    public function formulaData(): array
    {
        $data = $this->validated();

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'product_type' => $data['product_type'],
            'tier' => $data['tier'] ?? null,
        ];
    }
}
