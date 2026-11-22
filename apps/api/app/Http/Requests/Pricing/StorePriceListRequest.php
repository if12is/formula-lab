<?php

namespace App\Http\Requests\Pricing;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new price list: its name (unique inside the tenant). It starts active, empty and unpublished. */
class StorePriceListRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique('price_lists', 'name')->where('tenant_id', app(EnsureDefaultTenantAction::class)()->getKey())],
        ];
    }
}
