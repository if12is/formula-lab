<?php

namespace App\Http\Requests\Concerns;

trait TranslatesValidationMessages
{
    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) __('validation.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => __('validation.required'),
            'string' => __('validation.string'),
            'email' => __('validation.email'),
            'boolean' => __('validation.boolean'),
            'integer' => __('validation.integer'),
            'date' => __('validation.date'),
            'array' => __('validation.array'),
            'in' => __('validation.in'),
            'enum' => __('validation.enum'),
            'exists' => __('validation.exists'),
            'unique' => __('validation.unique'),
            'max.string' => __('validation.max.string'),
            'min.string' => __('validation.min.string'),
            'confirmed' => __('validation.confirmed'),
        ];
    }
}
