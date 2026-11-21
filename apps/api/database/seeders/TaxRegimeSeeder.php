<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Models\TaxRegime;
use App\Support\DecimalNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the source record of the tax decision and one dated regime per rate in config/tax.php (the rates a product may
 * choose). The owner decided the rates on 2026-11-21; the legal text behind them is still to be attached, so every regime is
 * seeded `pending` verification (orchestration/HUMAN-TODO.md) and its confidence is the one configured, never higher.
 * Safe to run again: a source and a regime that exist are kept. Run with `php artisan db:seed --class=TaxRegimeSeeder`.
 */
class TaxRegimeSeeder extends Seeder
{
    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'tax-regime-seeder';

    public function run(): void
    {
        DB::transaction(function (): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);

            $source = Source::query()->firstOrCreate(
                ['kind' => config('tax.source.kind'), 'title' => config('tax.source.title'), 'tenant_id' => null],
                ['retrieved_at' => config('tax.source.retrieved_at')],
            );

            foreach (config('tax.allowed_rates') as $rate) {
                TaxRegime::query()->firstOrCreate(
                    ['tax_type' => config('tax.type'), 'rate_pct' => DecimalNormalizer::normalize($rate), 'valid_from' => config('tax.regime.valid_from')],
                    ['source_id' => $source->getKey(), 'confidence' => config('tax.regime.confidence'), 'verification' => config('tax.regime.verification')],
                );
            }
        });
    }
}
