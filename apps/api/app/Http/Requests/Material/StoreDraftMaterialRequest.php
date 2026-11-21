<?php

namespace App\Http\Requests\Material;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A material created from inside the formula editor (D-09): no status (it is always a draft), an optional price per kg. */
class StoreDraftMaterialRequest extends FormRequest
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
            'trade_name' => ['required', 'string', 'max:'.config('formula.max_text_length')],
            'grade' => ['nullable', 'string', 'max:'.config('formula.max_text_length')],
            'function' => ['nullable', 'string', 'max:'.config('formula.max_text_length')],
            'substance_id' => ['nullable', 'uuid', 'exists:substances,id'],
            'is_mixture' => ['boolean'],
            'price_per_kg' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_mixture') && $this->filled('substance_id')) {
                $validator->errors()->add('substance_id', __('materials.errors.mixture_has_no_substance'));
            }
        });
    }

    /**
     * @return array{trade_name: string, grade: string|null, function: string|null, substance_id: string|null, is_mixture: bool}
     */
    public function materialData(): array
    {
        $data = $this->validated();

        return [
            'trade_name' => $data['trade_name'],
            'grade' => $data['grade'] ?? null,
            'function' => $data['function'] ?? null,
            'substance_id' => $data['substance_id'] ?? null,
            'is_mixture' => (bool) ($data['is_mixture'] ?? false),
        ];
    }

    public function pricePerKg(): ?string
    {
        return $this->validated('price_per_kg');
    }
}
