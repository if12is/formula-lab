<?php

namespace Database\Factories;

use App\Models\DecisionOverride;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DecisionOverride> */
class DecisionOverrideFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => 'publish-to-sanad',
            'subject_ref' => fake()->uuid(),
            'decision_hash' => hash('sha256', fake()->unique()->uuid()),
            'record' => [],
            'signed_by' => User::factory(),
            'role' => 'quality_manager',
            'reason' => fake()->sentence(),
            'signature_method' => 'password_reentry',
        ];
    }
}
