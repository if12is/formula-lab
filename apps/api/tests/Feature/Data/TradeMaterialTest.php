<?php

use App\Enums\TradeMaterialStatus;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('lets one substance have several trade grades', function () {
    $substance = Substance::factory()->create(['cas' => '1-11-1', 'mw' => '288.380']);
    TradeMaterial::factory()->for($substance)->create(['trade_name' => 'Material A 70%', 'grade' => '70']);
    TradeMaterial::factory()->for($substance)->create(['trade_name' => 'Material A 28%', 'grade' => '28']);

    expect($substance->tradeMaterials)->toHaveCount(2)
        ->and($substance->tradeMaterials->pluck('grade')->sort()->values()->all())->toBe(['28', '70']);
});

it('stores a mixture without a substance and keeps Sanad references as soft uuids', function () {
    $sanadProduct = fake()->uuid();
    $material = TradeMaterial::factory()->mixture()->create([
        'sanad_product_uuid' => $sanadProduct,
        'sanad_supplier_uuid' => fake()->uuid(),
    ]);

    $fresh = $material->fresh();
    expect($fresh->substance_id)->toBeNull()
        ->and($fresh->is_mixture)->toBeTrue()
        ->and($fresh->status)->toBe(TradeMaterialStatus::Draft)
        ->and($fresh->sanad_product_uuid)->toBe($sanadProduct);
});

it('keeps molecular weight exact, with no float rounding', function () {
    $substance = Substance::factory()->create(['mw' => '288.38000000000000000001']);

    expect($substance->fresh()->mw)->toBe('288.38000000000000000001');
});

it('rejects a float molecular weight', function () {
    expect(fn () => Substance::factory()->create(['mw' => 288.38]))->toThrow(InvalidArgumentException::class);
});

it('refuses a duplicate CAS but allows several substances without one', function () {
    Substance::factory()->create(['cas' => '1-11-1']);
    Substance::factory()->count(2)->create(['cas' => null]);

    expect(fn () => DB::transaction(fn () => Substance::factory()->create(['cas' => '1-11-1'])))
        ->toThrow(QueryException::class);
});

it('refuses an unknown trade material status at the database level', function () {
    $material = TradeMaterial::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('trade_materials')->where('id', $material->id)->update(['status' => 'archived'])))
        ->toThrow(QueryException::class);
});
