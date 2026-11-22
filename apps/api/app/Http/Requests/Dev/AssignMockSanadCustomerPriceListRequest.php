<?php

namespace App\Http\Requests\Dev;

/** The body of PUT customers/{uuid}/price-list (contract 8.4): `price_list_uuid` must be present; a uuid assigns, null detaches. */
class AssignMockSanadCustomerPriceListRequest extends MockSanadRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'price_list_uuid' => ['present', 'nullable', 'uuid'],
        ];
    }
}
