<?php

namespace Database\Seeders;

use App\Enums\TradeMaterialStatus;
use App\Models\TradeMaterial;
use Illuminate\Database\Seeder;

/**
 * Deterministic trade materials for the list-demo Playwright spec (/__dev/list-demo).
 * Idempotent: it removes its own rows (names starting "E2E ") before inserting them again.
 * Not part of DatabaseSeeder; run with `php artisan db:seed --class=ListDemoSeeder`.
 */
class ListDemoSeeder extends Seeder
{
    public function run(): void
    {
        TradeMaterial::query()->where('trade_name', 'like', 'E2E %')->get()->each->delete();

        $functions = ['Surfactant', 'Solvent', 'Fragrance', 'Preservative'];

        foreach (range(1, 24) as $index) {
            TradeMaterial::factory()->create([
                'trade_name' => sprintf('E2E Material %02d', $index),
                'grade' => (string) (10 + $index),
                'function' => $functions[$index % count($functions)],
                'is_mixture' => $index % 5 === 0,
                'status' => $index % 3 === 0 ? TradeMaterialStatus::Active : TradeMaterialStatus::Draft,
                'substance_id' => null,
            ]);
        }
    }
}
