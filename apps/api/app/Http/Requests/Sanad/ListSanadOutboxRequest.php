<?php

namespace App\Http\Requests\Sanad;

class ListSanadOutboxRequest extends SanadApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('sanad.outbox.page_size')],
        ];
    }
}
