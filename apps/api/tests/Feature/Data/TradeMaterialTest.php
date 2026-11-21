<?php

namespace Tests\Feature\Data;

use App\Enums\TradeMaterialStatus;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class TradeMaterialTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lets_one_substance_have_several_trade_grades(): void
    {
        $substance = Substance::factory()->create(['cas' => '1-11-1', 'mw' => '288.380']);
        TradeMaterial::factory()->for($substance)->create(['trade_name' => 'Material A 70%', 'grade' => '70']);
        TradeMaterial::factory()->for($substance)->create(['trade_name' => 'Material A 28%', 'grade' => '28']);

        $this->assertCount(2, $substance->tradeMaterials);
        $this->assertSame(['28', '70'], $substance->tradeMaterials->pluck('grade')->sort()->values()->all());
    }

    public function test_it_stores_a_mixture_without_a_substance_and_keeps_sanad_references_as_soft_uuids(): void
    {
        $sanadProduct = fake()->uuid();
        $material = TradeMaterial::factory()->mixture()->create([
            'sanad_product_uuid' => $sanadProduct,
            'sanad_supplier_uuid' => fake()->uuid(),
        ]);

        $fresh = $material->fresh();
        $this->assertNull($fresh->substance_id);
        $this->assertTrue($fresh->is_mixture);
        $this->assertSame(TradeMaterialStatus::Draft, $fresh->status);
        $this->assertSame($sanadProduct, $fresh->sanad_product_uuid);
    }

    public function test_it_keeps_molecular_weight_exact_with_no_float_rounding(): void
    {
        $substance = Substance::factory()->create(['mw' => '288.38000000000000000001']);

        $this->assertSame('288.38000000000000000001', $substance->fresh()->mw);
    }

    public function test_it_rejects_a_float_molecular_weight(): void
    {
        $this->assertThrowsException(InvalidArgumentException::class, fn () => Substance::factory()->create(['mw' => 288.38]));
    }

    public function test_it_refuses_a_duplicate_cas_but_allows_several_substances_without_one(): void
    {
        Substance::factory()->create(['cas' => '1-11-1']);
        Substance::factory()->count(2)->create(['cas' => null]);

        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => Substance::factory()->create(['cas' => '1-11-1'])));
    }

    public function test_it_refuses_an_unknown_trade_material_status_at_the_database_level(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('trade_materials')->where('id', $material->id)->update(['status' => 'archived'])));
    }
}
