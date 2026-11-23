<?php

namespace App\Http\Requests\Tiers;

use App\Enums\ProductTier;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The product and tier the tiers screen opens on (both optional: the screen starts empty). */
class ShowTierRequest extends FormRequest
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
            'formula' => ['nullable', 'uuid', Rule::exists('formulas', 'id')],
            'tier' => ['nullable', Rule::enum(ProductTier::class)],
        ];
    }

    public function tier(): ?ProductTier
    {
        return $this->filled('tier') ? ProductTier::from((string) $this->validated('tier')) : null;
    }
}
