<?php

namespace App\Http\Requests\Dev;

use App\Enums\Sanad\SanadItemKind;
use Illuminate\Validation\Rule;

class ListMockSanadRequest extends MockSanadRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:200'],
            'updated_since' => ['nullable', 'date'],
            'kind' => ['nullable', Rule::enum(SanadItemKind::class)],
            'branch' => ['nullable', 'string', 'max:100'],
        ];
    }
}
