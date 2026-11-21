<?php

namespace App\Http\Requests\Material;

use App\Enums\TradeMaterialStatus;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMaterialRequest extends FormRequest
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
            'status' => ['required', Rule::enum(TradeMaterialStatus::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A mixture is not one substance (the materials table keeps a null substance for it).
            if ($this->boolean('is_mixture') && $this->filled('substance_id')) {
                $validator->errors()->add('substance_id', __('materials.errors.mixture_has_no_substance'));
            }
        });
    }

    /**
     * The fields a material is saved with, with is_mixture always present.
     *
     * @return array{trade_name: string, grade: string|null, function: string|null, substance_id: string|null, is_mixture: bool, status: string}
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
            'status' => $data['status'],
        ];
    }
}
