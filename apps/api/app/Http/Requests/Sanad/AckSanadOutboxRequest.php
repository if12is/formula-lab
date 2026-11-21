<?php

namespace App\Http\Requests\Sanad;

use App\Enums\Sanad\SanadOutboxStatus;
use Illuminate\Validation\Rule;

class AckSanadOutboxRequest extends SanadApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_uuid' => ['nullable', 'uuid'],
            'acks' => ['present', 'array', 'max:'.config('sanad.outbox.page_size')],
            'acks.*.id' => ['required', 'string', 'max:64'],
            'acks.*.status' => ['required', Rule::in([
                SanadOutboxStatus::Applied->value,
                SanadOutboxStatus::Failed->value,
                SanadOutboxStatus::Conflict->value,
            ])],
            'acks.*.result' => ['nullable', 'array'],
            'acks.*.error' => ['nullable', 'array'],
        ];
    }
}
