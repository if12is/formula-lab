<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Http\Requests\Settings\Concerns\ValidatesExchangeRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A new dated exchange rate into the base currency. */
class StoreExchangeRateRequest extends FormRequest
{
    use TranslatesValidationMessages, ValidatesExchangeRate;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->exchangeRateRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validatePositiveRate($validator));
    }
}
