<?php

namespace App\Http\Requests\Dev;

class ConsumeMockSanadOrderRequest extends MockSanadRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['external_id' => (string) $this->route('external_id')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_uuid' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.self::DECIMAL, 'gt:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'branch_uuid' => ['nullable', 'uuid'],
            'consumed_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
