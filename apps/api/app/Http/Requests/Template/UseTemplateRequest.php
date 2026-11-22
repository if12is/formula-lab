<?php

namespace App\Http\Requests\Template;

use App\Enums\ProductTier;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\FormulaTemplate;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Starting a formula from a template: the new formula's identity and what fills each template line. */
class UseTemplateRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('templates.attributes')];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = Tenant::query()->where('name', config('formula.default_tenant_name'))->value('id');
        /** @var FormulaTemplate $template */
        $template = $this->route('template');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('formulas', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:'.config('formula.max_text_length')],
            'tier' => ['nullable', Rule::enum(ProductTier::class)],
            'fill' => ['present', 'array', 'max:'.count($template->lines)],
            'fill.*' => ['array'],
            'fill.*.material_id' => ['nullable', 'uuid', 'exists:trade_materials,id'],
            // A decimal typed by a person, kept as text: the engine reads it, PHP never casts it.
            'fill.*.percent_ww' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,9})?$/'],
        ];
    }

    /** @return array{code: string, name: string, tier: string|null, fill: array<string, array{material_id?: string|null, percent_ww?: string|null}>} */
    public function templateData(): array
    {
        $data = $this->validated();

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'tier' => $data['tier'] ?? null,
            'fill' => $data['fill'] ?? [],
        ];
    }
}
