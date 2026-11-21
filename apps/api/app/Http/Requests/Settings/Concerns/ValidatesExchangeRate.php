<?php

namespace App\Http\Requests\Settings\Concerns;

use App\Enums\Confidence;
use App\Support\Currencies;
use App\Support\DecimalCompare;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Fields and rules of an exchange rate as the add and edit modals submit it. */
trait ValidatesExchangeRate
{
    /** @return array<string, mixed> */
    protected function exchangeRateRules(): array
    {
        return [
            'from_currency' => ['required', 'string', Rule::in(Currencies::foreignCodes())],
            'rate' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,8})?$/'],
            'rate_date' => ['required', 'date_format:Y-m-d'],
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'confidence' => ['required', Rule::enum(Confidence::class)],
        ];
    }

    /** The rate must be above zero (the pattern alone accepts 0 and 0.0). */
    protected function validatePositiveRate(Validator $validator): void
    {
        if ($validator->errors()->has('rate')) {
            return;
        }

        if (DecimalCompare::compare((string) $this->input('rate'), '0') <= 0) {
            $validator->errors()->add('rate', __('exchange_rates.errors.rate_positive'));
        }
    }
}
