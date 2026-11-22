<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Enums\TemplateVerification;
use App\Models\FormulaTemplate;
use App\Models\ProductFamily;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaTemplate> */
class FormulaTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = 'template_'.fake()->unique()->bothify('??##');

        return [
            'code' => $code,
            'product_family_id' => ProductFamily::factory(),
            'name_ar' => 'قالب '.$code,
            'name_en' => 'Template '.$code,
            'verification_status' => TemplateVerification::Theoretical,
            'approved_by' => null,
            'approved_on' => null,
            'source_id' => Source::factory(),
            'confidence' => Confidence::L7Estimate,
            'lines' => [
                ['key' => 'water', 'role' => 'water', 'percentWW' => null, 'qs' => true, 'optional' => false],
                ['key' => 'anionic_surfactant', 'role' => 'anionic_surfactant', 'percentWW' => null, 'qs' => false, 'optional' => false],
                ['key' => 'preservative', 'role' => 'preservative', 'percentWW' => '0.5', 'qs' => false, 'optional' => false],
                ['key' => 'fragrance', 'role' => 'fragrance', 'percentWW' => null, 'qs' => false, 'optional' => true],
            ],
            'position' => 0,
        ];
    }
}
