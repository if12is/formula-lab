<?php

namespace Database\Seeders;

use App\Actions\Family\SyncProductFamiliesAction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the product family registry and the formula template library (K-01, K-09) from the engine's data files. Safe to run
 * again. Run with `php artisan db:seed --class=ProductFamilySeeder`.
 */
class ProductFamilySeeder extends Seeder
{
    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'product-family-seeder';

    public function run(SyncProductFamiliesAction $sync): void
    {
        DB::transaction(function () use ($sync): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);
            $sync();
        });
    }
}
