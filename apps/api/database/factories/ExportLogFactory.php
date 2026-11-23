<?php

namespace Database\Factories;

use App\Enums\ExportKind;
use App\Enums\PrintWatermark;
use App\Models\ExportLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExportLog> */
class ExportLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => null,
            'kind' => ExportKind::FormulaLabel,
            'subject_label' => 'F-'.fake()->unique()->numerify('####').' v1',
            'subject_id' => null,
            'formula_id' => null,
            'formula_version_id' => null,
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'watermark' => PrintWatermark::Approved,
            'exported_at' => now(),
        ];
    }
}
