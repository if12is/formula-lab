<?php

namespace App\Http\Requests\Pricing;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** The merchant (a cached Sanad customer, by uuid) to put on a price list. */
class AssignPriceListCustomerRequest extends FormRequest
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
            'customer_uuid' => ['required', 'uuid', 'exists:sanad_customers_cache,uuid'],
        ];
    }
}
