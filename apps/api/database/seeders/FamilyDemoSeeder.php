<?php

namespace Database\Seeders;

use App\Enums\TradeMaterialStatus;
use App\Models\TradeMaterial;
use Illuminate\Database\Seeder;

/**
 * Demo and e2e only: the registry and the template library, plus six clearly fictional materials (one per role of the dish liquid
 * template) so a template can be filled in a browser. The materials carry no composition and no price: they stand for nothing real.
 * Safe to run again. Run with `php artisan db:seed --class=FamilyDemoSeeder`.
 */
class FamilyDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductFamilySeeder::class);

        foreach (['Water', 'Anionic base', 'Salt', 'Acid', 'Preservative', 'Perfume'] as $name) {
            TradeMaterial::query()->firstOrCreate(
                ['trade_name' => "Family demo {$name}"],
                ['function' => $name, 'status' => TradeMaterialStatus::Active, 'composition_complete' => false, 'is_mixture' => false],
            );
        }
    }
}
