<?php

namespace Tests\Feature\Quality;

use App\Concerns\Auditable;
use App\Enums\AuditAction;
use App\Models\AssistantConsentEvent;
use App\Models\AssistantDisclosure;
use App\Models\AuditLog;
use App\Models\CalcRun;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionEvent;
use App\Models\CoaExtractionField;
use App\Models\DatasetVersion;
use App\Models\DecisionOverride;
use App\Models\ExportLog;
use App\Models\FormulaCostApproval;
use App\Models\FormulaPublication;
use App\Models\FormulaVersionProvenance;
use App\Models\FormulaVersionTransition;
use App\Models\LabMeasurement;
use App\Models\ProductionBatchCheck;
use App\Models\TermsAcceptance;
use App\Models\TestedValueMark;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Y-07: every table that holds quality evidence is either append-only (database trigger refuses UPDATE, DELETE and TRUNCATE) or audited
 * (every write lands in audit_log with who, when, before, after, why). The list is config/quality.php; a table in none of its lists fails here.
 */
class QualityTablesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, class-string<Model>> */
    private const APPEND_ONLY_MODELS = [
        'assistant_consent_events' => AssistantConsentEvent::class,
        'assistant_disclosures' => AssistantDisclosure::class,
        'audit_log' => AuditLog::class,
        'calc_runs' => CalcRun::class,
        'coa_extraction_events' => CoaExtractionEvent::class,
        'coa_extraction_fields' => CoaExtractionField::class,
        'coa_extractions' => CoaExtraction::class,
        'dataset_versions' => DatasetVersion::class,
        'decision_overrides' => DecisionOverride::class,
        'export_logs' => ExportLog::class,
        'formula_cost_approvals' => FormulaCostApproval::class,
        'formula_publications' => FormulaPublication::class,
        'formula_version_provenances' => FormulaVersionProvenance::class,
        'formula_version_transitions' => FormulaVersionTransition::class,
        'lab_measurements' => LabMeasurement::class,
        'production_batch_checks' => ProductionBatchCheck::class,
        'terms_acceptances' => TermsAcceptance::class,
        'tested_value_marks' => TestedValueMark::class,
    ];

    /** @return list<string> */
    private function databaseTables(): array
    {
        return array_column(DB::select('select tablename from pg_tables where schemaname = current_schema() order by tablename'), 'tablename');
    }

    public function test_every_table_of_the_database_is_classified_so_a_new_one_cannot_be_forgotten(): void
    {
        $classified = [...config('quality.append_only'), ...array_keys(config('quality.audited')), ...array_keys(config('quality.outside'))];

        $this->assertSame([], array_values(array_diff($this->databaseTables(), $classified)), 'Add each new table to config/quality.php.');
        $this->assertSame(count($classified), count(array_unique($classified)), 'A table is in one list only.');
    }

    public function test_the_append_only_models_cover_exactly_the_configured_append_only_tables(): void
    {
        $this->assertEqualsCanonicalizing(config('quality.append_only'), array_keys(self::APPEND_ONLY_MODELS));
    }

    public function test_every_append_only_table_has_row_and_truncate_triggers_in_the_database(): void
    {
        foreach (config('quality.append_only') as $table) {
            $triggers = DB::select(<<<'SQL'
select t.tgtype from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
where c.relname = ? and p.proname = 'fl_reject_modification' and not t.tgisinternal
SQL, [$table]);
            $types = array_map(fn (object $row): int => (int) $row->tgtype, $triggers);
            // tgtype bits: 1 = row level, 8 = delete, 16 = update, 32 = truncate.
            $this->assertTrue((bool) array_filter($types, fn (int $type): bool => ($type & 1) && ($type & 8) && ($type & 16)), "{$table}: no row trigger refusing update and delete");
            $this->assertTrue((bool) array_filter($types, fn (int $type): bool => (bool) ($type & 32)), "{$table}: no truncate trigger");
        }
    }

    public function test_an_attempt_to_change_an_append_only_row_fails_in_the_database_for_every_append_only_table(): void
    {
        foreach (self::APPEND_ONLY_MODELS as $table => $model) {
            $model::factory()->create();
            $key = (new $model)->getKeyName();
            $id = DB::table($table)->value($key);

            $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table($table)->where($key, $id)->delete()), 'append-only');
            $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::statement("truncate {$table} cascade")), 'append-only');
            $column = array_keys((array) DB::table($table)->where($key, $id)->first())[1];
            $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table($table)->where($key, $id)->update([$column => DB::raw($column)])), 'append-only');
        }
    }

    public function test_every_audited_table_has_a_model_with_the_audit_trait_and_its_first_write_reaches_the_trail(): void
    {
        foreach (config('quality.audited') as $table => $model) {
            $this->assertContains(Auditable::class, class_uses_recursive($model), "{$model} must use Auditable");
            $this->assertSame($table, (new $model)->getTable());

            $record = $model::factory()->create();

            $row = AuditLog::query()->where('table_name', $table)->where('record_id', (string) $record->getKey())->where('action', AuditAction::Create)->first();
            $this->assertNotNull($row, "{$table}: creation is not in audit_log");
            $this->assertNotNull($row->at);
        }
    }
}
