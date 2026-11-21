<?php

use App\Enums\AuditAction;
use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TradeMaterial;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('records the actor, before and after when a property value is updated', function () {
    $user = User::factory()->create();
    $pv = PropertyValue::factory()->create(['value' => '1.5']);
    $this->actingAs($user);

    $pv->withAuditReason('Corrected from COA')->update(['value' => '1.6']);

    $row = AuditLog::where('table_name', 'property_values')->where('record_id', $pv->id)
        ->where('action', AuditAction::Update)->sole();

    expect($row->before)->toBe(['value' => '1.5'])
        ->and($row->after)->toBe(['value' => '1.6'])
        ->and($row->actor_id)->toBe($user->id)
        ->and($row->reason)->toBe('Corrected from COA')
        ->and($row->at)->not->toBeNull();
});

it('records creation and deletion', function () {
    $substance = Substance::factory()->create(['name_en' => 'Substance A']);
    $substance->delete();

    $rows = AuditLog::where('record_id', $substance->id)->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe([AuditAction::Create, AuditAction::Delete])
        ->and($rows[0]->before)->toBeNull()
        ->and($rows[0]->after['name_en'])->toBe('Substance A')
        ->and($rows[1]->before['name_en'])->toBe('Substance A')
        ->and($rows[1]->after)->toBeNull()
        ->and($rows[0]->actor_id)->toBeNull();
});

it('audits every reference-data model', function () {
    $source = Source::factory()->create();
    $material = TradeMaterial::factory()->create();
    PropertyValue::factory()->create(['source_id' => $source->id]);
    Price::factory()->create(['source_id' => $source->id, 'trade_material_id' => $material->id]);

    expect(AuditLog::where('action', AuditAction::Create)->pluck('table_name')->unique()->sort()->values()->all())
        ->toBe(['prices', 'property_values', 'sources', 'substances', 'trade_materials']);
});

it('does not write an audit row for a save that changes nothing', function () {
    $source = Source::factory()->create();
    $count = AuditLog::count();

    $source->update(['title' => $source->title]);
    $source->touch();

    expect(AuditLog::count())->toBe($count);
});

it('keeps decimals exact in the audit trail', function () {
    $price = Price::factory()->create(['amount' => '10.250']);
    $price->update(['amount' => '10.2500000000001']);

    $row = AuditLog::where('record_id', $price->id)->where('action', AuditAction::Update)->sole();

    expect($row->before['amount'])->toBe('10.250')->and($row->after['amount'])->toBe('10.2500000000001');
});

it('refuses to update or delete an audit row, in the model and in the database', function () {
    $row = AuditLog::factory()->create();

    expect(fn () => $row->update(['reason' => 'tamper']))->toThrow(ImmutableRecordException::class)
        ->and(fn () => $row->delete())->toThrow(ImmutableRecordException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $row->id)->update(['reason' => 'tamper'])))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $row->id)->delete()))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::statement('truncate audit_log')))
        ->toThrow(QueryException::class, 'append-only');

    expect($row->fresh()->reason)->toBeNull();
});

it('rejects an unknown audit action', function () {
    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->insert([
        'table_name' => 'substances', 'record_id' => 'x', 'action' => 'upsert',
    ])))->toThrow(QueryException::class);
});
