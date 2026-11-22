<?php

namespace App\Http\Requests\Fragrance;

use App\Enums\Confidence;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIfraCertificateRequest extends FormRequest
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
            'certificate_number' => [
                'required', 'string', 'max:100',
                Rule::unique('ifra_certificates', 'certificate_number')->where('trade_material_id', $this->route('material')?->getKey()),
            ],
            'ifra_amendment' => ['nullable', 'string', 'max:50'],
            'issuer' => ['nullable', 'string', 'max:150'],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'confidence' => ['required', Rule::enum(Confidence::class)],
        ];
    }
}
