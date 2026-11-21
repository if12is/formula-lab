<?php

namespace Database\Seeders;

use App\Actions\Compliance\ImportRuleSetAction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the rule files shipped with the engine (packages/engine/data/rules/*.json) as DRAFT rule sets. Today that is the
 * methanol rule of N-09, which carries `pendingSource`: its regulatory reference is still to be provided (HUMAN-TODO), so
 * it can never be published and is never shown as a PASS. Safe to run again: a code and version already there are kept.
 * Run with `php artisan db:seed --class=ComplianceRuleSetSeeder`.
 */
class ComplianceRuleSetSeeder extends Seeder
{
    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'compliance-rule-set-seeder';

    /** The name shown for each shipped file, by rule set id. */
    private const NAMES = [
        'methanol-fragrance' => ['name_key' => 'compliance.seed.methanol_name', 'notes_key' => 'compliance.seed.methanol_notes'],
    ];

    public function run(ImportRuleSetAction $import): void
    {
        DB::transaction(function () use ($import): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);

            foreach (glob(base_path('../../packages/engine/data/rules/*.json')) ?: [] as $file) {
                $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($document) || ! isset($document['id'])) {
                    throw new RuntimeException("Not a rule set: {$file}");
                }

                $labels = self::NAMES[$document['id']] ?? null;
                $import(
                    $document,
                    $labels === null ? (string) $document['id'] : __($labels['name_key']),
                    $labels === null ? null : __($labels['notes_key']),
                );
            }
        });
    }
}
