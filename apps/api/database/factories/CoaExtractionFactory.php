<?php

namespace Database\Factories;

use App\Models\CoaExtraction;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CoaExtraction> */
class CoaExtractionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'trade_material_id' => TradeMaterial::factory(),
            'uploaded_by' => null,
            'file_path' => 'coa/test/'.fake()->unique()->uuid().'.pdf',
            'file_name' => 'coa.pdf',
            'file_mime' => 'application/pdf',
            'file_size' => 1024,
            'file_sha256' => hash('sha256', fake()->unique()->uuid()),
            'supplier' => null,
            'lot_no' => null,
            'document_date' => null,
            'text_check' => 'verified',
            'model' => 'test-model',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'raw_answer' => [],
            'created_at' => now(),
        ];
    }
}
