<?php

namespace App\Http\Requests\Dev;

class ReceiveMockSanadOrderRequest extends MockSanadRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'external_id' => (string) $this->route('external_id'),
            'finished_good_uuid' => $this->input('finished_good_uuid', $this->input('product_uuid')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'finished_good_uuid' => ['required', 'uuid'],
            'quantity' => ['required', 'string', 'regex:'.self::DECIMAL, 'gt:0'],
            'unit_cost' => ['required', 'string', 'regex:'.self::DECIMAL],
            'batch_number' => ['required', 'string', 'max:100'],
            'production_date' => ['required', 'date_format:Y-m-d'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:production_date'],
            'uom' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'max:100'],
            // Legacy alias of branch_code, still accepted by Sanad.
            'branch' => ['nullable', 'string', 'max:100'],
        ];
    }
}
