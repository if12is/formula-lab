<?php

use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->service = app(FormulaVersionService::class);
    $this->formula = Formula::factory()->create();
    $this->a = TradeMaterial::factory()->create();
    $this->b = TradeMaterial::factory()->create();
    $this->lines = [
        ['trade_material_id' => $this->a->id, 'percent_ww' => '10.50', 'phase' => 'A'],
        ['trade_material_id' => $this->b->id, 'percent_ww' => null, 'is_qs' => true],
    ];
});

it('creates version 1 as a draft with ordered lines and a hash', function () {
    $user = User::factory()->create();

    $v1 = $this->service->createVersion($this->formula, $this->lines, null, $user);

    expect($v1->version)->toBe(1)
        ->and($v1->status)->toBe(FormulaStatus::Draft)
        ->and($v1->reason)->toBeNull()
        ->and($v1->parent_version_id)->toBeNull()
        ->and($v1->created_by)->toBe($user->id)
        ->and($v1->lines->pluck('line_no')->all())->toBe([1, 2])
        ->and($v1->lines[0]->percent_ww)->toBe('10.50')
        ->and($v1->lines[1]->is_qs)->toBeTrue()
        ->and($v1->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($v1->verifyHash())->toBeTrue();
});

it('always inserts a new version and leaves the old one untouched', function () {
    $v1 = $this->service->createVersion($this->formula, $this->lines, null);
    $before = DB::table('formula_versions')->where('id', $v1->id)->first();
    $beforeLines = DB::table('formula_lines')->where('formula_version_id', $v1->id)->orderBy('line_no')->get();

    $changed = $this->lines;
    $changed[0]['percent_ww'] = '12';
    $v2 = $this->service->createVersion($this->formula, $changed, 'Raised A for viscosity');

    expect($v2->version)->toBe(2)
        ->and($v2->parent_version_id)->toBe($v1->id)
        ->and($v2->reason)->toBe('Raised A for viscosity')
        ->and($v2->hash)->not->toBe($v1->hash)
        ->and(FormulaVersion::count())->toBe(2)
        ->and(DB::table('formula_versions')->where('id', $v1->id)->first())->toEqual($before)
        ->and(DB::table('formula_lines')->where('formula_version_id', $v1->id)->orderBy('line_no')->get())->toEqual($beforeLines);
});

it('gives equal content an equal hash regardless of decimal formatting', function () {
    $other = Formula::factory()->create();
    $v1 = $this->service->createVersion($this->formula, $this->lines, null);

    $same = $this->lines;
    $same[0]['percent_ww'] = '10.5';
    $v2 = $this->service->createVersion($other, $same, null);

    expect($v2->hash)->toBe($v1->hash);
});

it('detects lines that no longer match the recorded hash', function () {
    $v1 = $this->service->createVersion($this->formula, $this->lines, null);
    DB::table('formula_lines')->where('formula_version_id', $v1->id)->where('line_no', 1)->update(['percent_ww' => '99']);

    expect($v1->fresh()->verifyHash())->toBeFalse();
});

it('requires a reason from the second version on', function (?string $reason) {
    $this->service->createVersion($this->formula, $this->lines, null);

    expect(fn () => $this->service->createVersion($this->formula, $this->lines, $reason))
        ->toThrow(InvalidArgumentException::class, 'reason');
    expect(FormulaVersion::count())->toBe(1);
})->with([null, '', '   ']);

it('rejects an empty line list', function () {
    expect(fn () => $this->service->createVersion($this->formula, [], null))->toThrow(InvalidArgumentException::class);
});

it('rejects float percentages and a missing percentage on a non q.s. line', function () {
    expect(fn () => $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => 10.5]], null))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => DB::transaction(fn () => $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => null]], null)))
        ->toThrow(QueryException::class, 'formula_lines_percent_check');
    expect(FormulaVersion::count())->toBe(0);
});

it('rolls back the whole version when a line is invalid', function () {
    expect(fn () => $this->service->createVersion($this->formula, [
        ['trade_material_id' => $this->a->id, 'percent_ww' => '10'],
        ['trade_material_id' => fake()->uuid(), 'percent_ww' => '5'],
    ], null))->toThrow(QueryException::class);

    expect(FormulaVersion::count())->toBe(0);
});

it('enforces unique version numbers and the reason rule in the database', function () {
    $v1 = $this->service->createVersion($this->formula, $this->lines, null);

    expect(fn () => DB::transaction(fn () => FormulaVersion::factory()->create(['formula_id' => $this->formula->id, 'version' => 1])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => FormulaVersion::factory()->create(['formula_id' => $this->formula->id, 'version' => 2, 'reason' => null])))
        ->toThrow(QueryException::class, 'formula_versions_reason_check');
    expect($v1->fresh()->version)->toBe(1);
});

it('numbers versions per formula', function () {
    $other = Formula::factory()->create();
    $this->service->createVersion($this->formula, $this->lines, null);

    expect($this->service->createVersion($other, $this->lines, null)->version)->toBe(1);
});
