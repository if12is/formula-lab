<?php

namespace App\Http\Requests\Sanad;

use App\Enums\Sanad\SanadOp;
use Illuminate\Validation\Rule;

class ApplySanadChangesRequest extends SanadApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_uuid' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:200'],
            'items' => ['present', 'array', 'max:'.config('sanad.inbound.max_items_per_push')],
            'items.*.entity' => ['required', 'string', 'max:50'],
            'items.*.uuid' => ['required', 'string', 'max:64'],
            'items.*.op' => ['required', Rule::enum(SanadOp::class)],
            'items.*.updated_at' => ['required', 'date'],
            'items.*.payload' => ['present', 'array'],
        ];
    }
}
