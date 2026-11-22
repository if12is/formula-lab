<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\IfraCertificate;
use App\Models\Source;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IfraCertificate> */
class IfraCertificateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'trade_material_id' => TradeMaterial::factory(),
            'certificate_number' => 'CERT-'.fake()->unique()->bothify('####'),
            'ifra_amendment' => null,
            'issuer' => null,
            'issued_on' => '2026-03-01',
            'source_id' => Source::factory(),
            'confidence' => Confidence::L1CoaBatch,
        ];
    }
}
