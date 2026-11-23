<?php

namespace Database\Factories;

use App\Enums\ProductTier;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TierProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TierProfile> */
class TierProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_type' => 'test_product',
            'tier' => ProductTier::Economy,
            'profile' => [
                'targetRetailPrice' => ['per' => 'kg', 'value' => null],
                'constraints' => [
                    'totalActiveMinPct' => null,
                    'totalActiveMaxPct' => null,
                    'maxCostEGPperKg' => null,
                    'forbiddenMaterials' => [],
                    'requiredMaterials' => [],
                    'materialBounds' => [],
                ],
            ],
            'source_id' => Source::factory(),
        ];
    }
}
