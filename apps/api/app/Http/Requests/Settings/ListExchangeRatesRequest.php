<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The exchange rates page. The list's own query string (search, filters, sort, page) is read by ListQuery; only the
 * link that opens the add modal pre-filled ("add a rate for USD on 2026-11-20") is validated here.
 */
class ListExchangeRatesRequest extends FormRequest
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
            'new_currency' => ['nullable', 'string', Rule::in(Currencies::foreignCodes())],
            'new_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array{currency: string|null, rate_date: string|null}|null The pre-filled add modal, or null. */
    public function prefill(): ?array
    {
        $data = $this->validator->valid();

        if (! isset($data['new_currency']) && ! isset($data['new_date'])) {
            return null;
        }

        return ['currency' => $data['new_currency'] ?? null, 'rate_date' => $data['new_date'] ?? null];
    }
}
