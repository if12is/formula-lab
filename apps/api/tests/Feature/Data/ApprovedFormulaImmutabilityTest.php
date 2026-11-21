<?php

use App\Enums\FormulaStatus;
use App\Exceptions\ImmutableRecordException;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaDiffService;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function approvedVersionWithLine(): FormulaVersion
{
    $version = FormulaVersion::factory()->create();
    FormulaLine::factory()->create(['formula_version_id' => $version->id]);
    $version->update(['status' => FormulaStatus::Approved]);

    return $version->fresh();
}

describe('approved versions are immutable', function () {
    it('allows moving a draft through lab testing to approved', function () {
        $version = FormulaVersion::factory()->create();

        $version->update(['status' => FormulaStatus::LabTesting]);
        $version->update(['status' => FormulaStatus::Approved]);

        expect($version->fresh()->isApproved())->toBeTrue();
    });

    it('throws a domain exception on model update or delete', function () {
        $version = approvedVersionWithLine();

        expect(fn () => $version->update(['reason' => 'x']))->toThrow(ImmutableRecordException::class)
            ->and(fn () => $version->update(['status' => FormulaStatus::Draft]))->toThrow(ImmutableRecordException::class)
            ->and(fn () => $version->delete())->toThrow(ImmutableRecordException::class);
    });

    it('rejects update and delete at the database level', function () {
        $version = approvedVersionWithLine();

        expect(fn () => DB::transaction(fn () => DB::table('formula_versions')->where('id', $version->id)->update(['status' => 'draft'])))
            ->toThrow(QueryException::class, 'immutable')
            ->and(fn () => DB::transaction(fn () => DB::table('formula_versions')->where('id', $version->id)->update(['hash' => str_repeat('0', 64)])))
            ->toThrow(QueryException::class, 'immutable')
            ->and(fn () => DB::transaction(fn () => DB::table('formula_versions')->where('id', $version->id)->delete()))
            ->toThrow(QueryException::class, 'immutable');

        expect($version->fresh()->status)->toBe(FormulaStatus::Approved);
    });

    it('protects the lines of an approved version in the model and in the database', function () {
        $version = approvedVersionWithLine();
        $line = $version->lines()->first();

        expect(fn () => $line->update(['percent_ww' => '50']))->toThrow(ImmutableRecordException::class)
            ->and(fn () => $line->delete())->toThrow(ImmutableRecordException::class)
            ->and(fn () => FormulaLine::factory()->create(['formula_version_id' => $version->id, 'line_no' => 2]))
            ->toThrow(ImmutableRecordException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('formula_lines')->where('id', $line->id)->update(['percent_ww' => '50'])))
            ->toThrow(QueryException::class, 'immutable')
            ->and(fn () => DB::transaction(fn () => DB::table('formula_lines')->where('id', $line->id)->delete()))
            ->toThrow(QueryException::class, 'immutable')
            ->and(fn () => DB::transaction(fn () => DB::table('formula_lines')->insert([
                'id' => fake()->uuid(), 'formula_version_id' => $version->id, 'line_no' => 9,
                'trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => '1', 'is_qs' => false,
            ])))->toThrow(QueryException::class, 'immutable');
    });

    it('leaves draft versions and their lines editable', function () {
        $version = FormulaVersion::factory()->create();
        $line = FormulaLine::factory()->create(['formula_version_id' => $version->id]);

        $line->update(['percent_ww' => '20']);
        $version->update(['reason' => 'fine']);
        $line->delete();

        expect($version->fresh()->reason)->toBe('fine')->and(FormulaLine::count())->toBe(0);
    });

    it('lets a new version be created from an approved one', function () {
        $approved = approvedVersionWithLine();
        $formula = $approved->formula;

        $next = app(FormulaVersionService::class)->createVersion($formula, [
            ['trade_material_id' => $approved->lines->first()->trade_material_id, 'percent_ww' => '11'],
        ], 'Adjusted after review');

        expect($next->version)->toBe(2)->and($next->status)->toBe(FormulaStatus::Draft)
            ->and($approved->fresh()->isApproved())->toBeTrue();
    });
});

describe('formula diff', function () {
    beforeEach(function () {
        $this->service = app(FormulaVersionService::class);
        $this->diff = app(FormulaDiffService::class);
        $this->formula = Formula::factory()->create();
        [$this->a, $this->b, $this->c] = TradeMaterial::factory()->count(3)->create()->all();
    });

    it('reports added, removed and changed lines plus reason and status changes', function () {
        $v1 = $this->service->createVersion($this->formula, [
            ['trade_material_id' => $this->a->id, 'percent_ww' => '10', 'phase' => 'A'],
            ['trade_material_id' => $this->b->id, 'percent_ww' => '5.0'],
            ['trade_material_id' => $this->c->id, 'is_qs' => true],
        ], null);
        $v2 = $this->service->createVersion($this->formula, [
            ['trade_material_id' => $this->a->id, 'percent_ww' => '12', 'phase' => 'B'],
            ['trade_material_id' => $this->b->id, 'percent_ww' => '5'],
        ], 'Dropped C, raised A');
        $v2->update(['status' => FormulaStatus::LabTesting]);

        $result = $this->diff->diff($v1, $v2->fresh());

        expect($result['added'])->toBe([])
            ->and($result['removed'])->toHaveCount(1)
            ->and($result['removed'][0]['tradeMaterialId'])->toBe($this->c->id)
            ->and($result['removed'][0]['isQs'])->toBeTrue()
            ->and($result['changed'])->toBe([[
                'trade_material_id' => $this->a->id,
                'changes' => [
                    'percent_ww' => ['from' => '10', 'to' => '12'],
                    'phase' => ['from' => 'A', 'to' => 'B'],
                ],
            ]])
            ->and($result['meta'])->toBe([
                'reason' => ['from' => null, 'to' => 'Dropped C, raised A'],
                'status' => ['from' => 'draft', 'to' => 'lab_testing'],
            ]);
    });

    it('treats a material swap as one removed plus one added line', function () {
        $v1 = $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => '10']], null);
        $v2 = $this->service->createVersion($this->formula, [['trade_material_id' => $this->b->id, 'percent_ww' => '10']], 'Swap');

        $result = $this->diff->diff($v1, $v2);

        expect($result['removed'][0]['tradeMaterialId'])->toBe($this->a->id)
            ->and($result['added'][0]['tradeMaterialId'])->toBe($this->b->id)
            ->and($result['changed'])->toBe([]);
    });

    it('reports no differences for identical content and ignores decimal formatting', function () {
        $v1 = $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => '10.0']], null);
        $v2 = $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => '10']], 'Same');

        $result = $this->diff->diff($v1, $v2);

        expect($result['added'])->toBe([])->and($result['removed'])->toBe([])->and($result['changed'])->toBe([])
            ->and($result['meta'])->toHaveKey('reason')->not->toHaveKey('status');
    });

    it('pairs repeated materials by occurrence', function () {
        $v1 = $this->service->createVersion($this->formula, [
            ['trade_material_id' => $this->a->id, 'percent_ww' => '1', 'phase' => 'A'],
            ['trade_material_id' => $this->a->id, 'percent_ww' => '2', 'phase' => 'B'],
        ], null);
        $v2 = $this->service->createVersion($this->formula, [
            ['trade_material_id' => $this->a->id, 'percent_ww' => '1', 'phase' => 'A'],
            ['trade_material_id' => $this->a->id, 'percent_ww' => '3', 'phase' => 'B'],
        ], 'Change second');

        $result = $this->diff->diff($v1, $v2);

        expect($result['changed'])->toHaveCount(1)
            ->and($result['changed'][0]['changes'])->toBe(['percent_ww' => ['from' => '2', 'to' => '3']]);
    });
});
