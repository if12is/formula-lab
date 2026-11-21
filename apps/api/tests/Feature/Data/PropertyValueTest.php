<?php

namespace Tests\Feature\Data;

use App\Enums\Confidence;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class PropertyValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_property_as_a_single_value(): void
    {
        $pv = PropertyValue::factory()->create(['value' => '1.050', 'min' => null, 'max' => null]);

        $fresh = $pv->fresh();
        $this->assertSame('1.050', $fresh->value);
        $this->assertNull($fresh->min);
        $this->assertNull($fresh->max);
    }

    public function test_it_stores_a_property_as_a_range(): void
    {
        $pv = PropertyValue::factory()->range('187', '194')->create();

        $fresh = $pv->fresh();
        $this->assertNull($fresh->value);
        $this->assertSame('187', $fresh->min);
        $this->assertSame('194', $fresh->max);
    }

    public function test_it_stores_an_open_ended_range_with_only_a_max(): void
    {
        $pv = PropertyValue::factory()->create(['value' => null, 'max' => '0.5']);

        $fresh = $pv->fresh();
        $this->assertNull($fresh->min);
        $this->assertSame('0.5', $fresh->max);
    }

    public function test_it_rejects_a_row_with_neither_a_value_nor_a_bound(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => PropertyValue::factory()->create(['value' => null])), 'property_values_has_value_check');
    }

    public function test_it_rejects_a_range_with_min_above_max(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => PropertyValue::factory()->range('10', '5')->create()), 'property_values_range_order_check');
    }

    public function test_it_requires_a_source(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => PropertyValue::factory()->create(['source_id' => null])));
    }

    public function test_it_accepts_every_confidence_level_and_rejects_an_unknown_one(): void
    {
        foreach (Confidence::cases() as $level) {
            PropertyValue::factory()->create(['confidence' => $level]);
        }
        $this->assertSame(count(Confidence::cases()), PropertyValue::count());

        $pv = PropertyValue::first();
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('property_values')->where('id', $pv->id)->update(['confidence' => 'L9_guess'])), 'property_values_confidence_check');
    }

    public function test_it_rejects_float_values(): void
    {
        $this->assertThrowsException(InvalidArgumentException::class, fn () => PropertyValue::factory()->create(['value' => 1.5]));
    }

    public function test_it_attaches_to_substances_and_trade_materials_through_the_entity_relation(): void
    {
        $substance = Substance::factory()->create();
        $material = TradeMaterial::factory()->create();
        $source = Source::factory()->create();

        $a = PropertyValue::factory()->create(['entity_type' => 'substance', 'entity_id' => $substance->id, 'source_id' => $source->id]);
        $b = PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $material->id, 'source_id' => $source->id]);

        $this->assertTrue($a->entity->is($substance));
        $this->assertTrue($b->entity->is($material));
        $this->assertCount(1, $substance->propertyValues);
        $this->assertTrue($a->source->is($source));
    }

    public function test_it_rejects_an_unknown_entity_type(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => PropertyValue::factory()->create(['entity_type' => 'supplier'])), 'property_values_entity_type_check');
    }
}
