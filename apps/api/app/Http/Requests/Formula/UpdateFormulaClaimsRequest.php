<?php

namespace App\Http\Requests\Formula;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\Families\FamilyData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The claims a product makes: only codes the disinfectant flag data knows (the app keeps no second list). */
class UpdateFormulaClaimsRequest extends FormRequest
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
            'claims' => ['present', 'array'],
            'claims.*' => ['string', Rule::in(app(FamilyData::class)->claimCodes())],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), 'claims' => __('disinfection.claims_label'), 'claims.*' => __('disinfection.claims_label')];
    }

    /** @return list<string> */
    public function claims(): array
    {
        return array_values($this->validated('claims'));
    }
}
