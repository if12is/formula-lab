<?php

use App\Enums\Confidence;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores a property as a single value', function () {
    $pv = PropertyValue::factory()->create(['value' => '1.050', 'min' => null, 'max' => null]);

    $fresh = $pv->fresh();
    expect($fresh->value)->toBe('1.050')->and($fresh->min)->toBeNull()->and($fresh->max)->toBeNull();
});

it('stores a property as a range', function () {
    $pv = PropertyValue::factory()->range('187', '194')->create();

    $fresh = $pv->fresh();
    expect($fresh->value)->toBeNull()->and($fresh->min)->toBe('187')->and($fresh->max)->toBe('194');
});

it('stores an open-ended range with only a max', function () {
    $pv = PropertyValue::factory()->create(['value' => null, 'max' => '0.5']);

    $fresh = $pv->fresh();
    expect($fresh->min)->toBeNull()->and($fresh->max)->toBe('0.5');
});

it('rejects a row with neither a value nor a bound', function () {
    expect(fn () => DB::transaction(fn () => PropertyValue::factory()->create(['value' => null])))
        ->toThrow(QueryException::class, 'property_values_has_value_check');
});

it('rejects a range with min above max', function () {
    expect(fn () => DB::transaction(fn () => PropertyValue::factory()->range('10', '5')->create()))
        ->toThrow(QueryException::class, 'property_values_range_order_check');
});

it('requires a source', function () {
    expect(fn () => DB::transaction(fn () => PropertyValue::factory()->create(['source_id' => null])))
        ->toThrow(QueryException::class);
});

it('accepts every confidence level and rejects an unknown one', function () {
    foreach (Confidence::cases() as $level) {
        PropertyValue::factory()->create(['confidence' => $level]);
    }
    expect(PropertyValue::count())->toBe(count(Confidence::cases()));

    $pv = PropertyValue::first();
    expect(fn () => DB::transaction(fn () => DB::table('property_values')->where('id', $pv->id)->update(['confidence' => 'L9_guess'])))
        ->toThrow(QueryException::class, 'property_values_confidence_check');
});

it('rejects float values', function () {
    expect(fn () => PropertyValue::factory()->create(['value' => 1.5]))->toThrow(InvalidArgumentException::class);
});

it('attaches to substances and trade materials through the entity relation', function () {
    $substance = Substance::factory()->create();
    $material = TradeMaterial::factory()->create();
    $source = Source::factory()->create();

    $a = PropertyValue::factory()->create(['entity_type' => 'substance', 'entity_id' => $substance->id, 'source_id' => $source->id]);
    $b = PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $material->id, 'source_id' => $source->id]);

    expect($a->entity->is($substance))->toBeTrue()
        ->and($b->entity->is($material))->toBeTrue()
        ->and($substance->propertyValues)->toHaveCount(1)
        ->and($a->source->is($source))->toBeTrue();
});

it('rejects an unknown entity type', function () {
    expect(fn () => DB::transaction(fn () => PropertyValue::factory()->create(['entity_type' => 'supplier'])))
        ->toThrow(QueryException::class, 'property_values_entity_type_check');
});
