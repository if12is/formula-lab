<?php

namespace Database\Factories;

use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\SanadCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceListCustomer> */
class PriceListCustomerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'customer_uuid' => SanadCustomer::factory(),
        ];
    }
}
