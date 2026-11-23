<?php

namespace App\Http\Requests\Packaging;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** A pack image: a real image of an accepted type (jpg, png or webp; never SVG) up to the size limit in config packaging.image. */
class StorePackagingBomImageRequest extends FormRequest
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
            'image' => ['required', 'file', 'image', 'mimes:'.implode(',', (array) config('packaging.image.mimes')), 'max:'.(int) config('packaging.image.max_kb')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('packaging.bom.attributes')];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'image.mimes' => __('packaging.bom.errors.image_type', ['types' => strtoupper(implode(', ', (array) config('packaging.image.mimes')))]),
            'image.image' => __('packaging.bom.errors.image_type', ['types' => strtoupper(implode(', ', (array) config('packaging.image.mimes')))]),
            'image.max' => __('packaging.bom.errors.image_size', ['size' => (int) config('packaging.image.max_kb') / 1024]),
        ];
    }
}
