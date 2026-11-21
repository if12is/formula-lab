<?php

namespace Tests\Feature\Data;

use App\Enums\AuditAction;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TradeMaterial;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_actor_before_and_after_when_a_property_value_is_updated(): void
    {
        $user = User::factory()->create();
        $pv = PropertyValue::factory()->create(['value' => '1.5']);
        $this->actingAs($user);

        $pv->withAuditReason('Corrected from COA')->update(['value' => '1.6']);

        $row = AuditLog::where('table_name', 'property_values')->where('record_id', $pv->id)
            ->where('action', AuditAction::Update)->sole();

        $this->assertSame(['value' => '1.5'], $row->before);
        $this->assertSame(['value' => '1.6'], $row->after);
        $this->assertSame($user->id, $row->actor_id);
        $this->assertSame('Corrected from COA', $row->reason);
        $this->assertNotNull($row->at);
    }

    public function test_it_records_creation_and_deletion(): void
    {
        $substance = Substance::factory()->create(['name_en' => 'Substance A']);
        $substance->delete();

        $rows = AuditLog::where('record_id', $substance->id)->orderBy('id')->get();

        $this->assertSame([AuditAction::Create, AuditAction::Delete], $rows->pluck('action')->all());
        $this->assertNull($rows[0]->before);
        $this->assertSame('Substance A', $rows[0]->after['name_en']);
        $this->assertSame('Substance A', $rows[1]->before['name_en']);
        $this->assertNull($rows[1]->after);
        $this->assertNull($rows[0]->actor_id);
    }

    public function test_it_audits_every_reference_data_model(): void
    {
        $source = Source::factory()->create();
        $material = TradeMaterial::factory()->create();
        PropertyValue::factory()->create(['source_id' => $source->id]);
        Price::factory()->create(['source_id' => $source->id, 'trade_material_id' => $material->id]);

        $this->assertSame(
            ['prices', 'property_values', 'sources', 'substances', 'trade_materials'],
            AuditLog::where('action', AuditAction::Create)->pluck('table_name')->unique()->sort()->values()->all(),
        );
    }

    public function test_it_does_not_write_an_audit_row_for_a_save_that_changes_nothing(): void
    {
        $source = Source::factory()->create();
        $count = AuditLog::count();

        $source->update(['title' => $source->title]);
        $source->touch();

        $this->assertSame($count, AuditLog::count());
    }

    public function test_it_keeps_decimals_exact_in_the_audit_trail(): void
    {
        $price = Price::factory()->create(['amount' => '10.250']);
        $price->update(['amount' => '10.2500000000001']);

        $row = AuditLog::where('record_id', $price->id)->where('action', AuditAction::Update)->sole();

        $this->assertSame('10.250', $row->before['amount']);
        $this->assertSame('10.2500000000001', $row->after['amount']);
    }

    public function test_it_refuses_to_update_or_delete_an_audit_row_in_the_model_and_in_the_database(): void
    {
        $row = AuditLog::factory()->create();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $row->update(['reason' => 'tamper']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $row->delete());
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $row->id)->update(['reason' => 'tamper'])), 'append-only');
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $row->id)->delete()), 'append-only');
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::statement('truncate audit_log')), 'append-only');

        $this->assertNull($row->fresh()->reason);
    }

    public function test_it_words_the_append_only_exception_from_the_lang_files(): void
    {
        $row = AuditLog::factory()->create();

        try {
            $row->delete();
            $this->fail('Expected ImmutableRecordException.');
        } catch (ImmutableRecordException $exception) {
            $this->assertSame(__('exceptions.append_only', ['table' => 'audit_log']), $exception->getMessage());
        }
    }

    public function test_it_rejects_an_unknown_audit_action(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('audit_log')->insert([
            'table_name' => 'substances', 'record_id' => 'x', 'action' => 'upsert',
        ])));
    }
}
