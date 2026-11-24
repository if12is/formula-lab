<?php

namespace Tests\Feature\Data;

use App\Enums\FormulaStatus;
use App\Exceptions\Domain\InvalidFormulaVersionException;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormulaVersionTest extends TestCase
{
    use RefreshDatabase;

    private FormulaVersionService $service;

    private Formula $formula;

    private TradeMaterial $a;

    private TradeMaterial $b;

    /** @var list<array<string, mixed>> */
    private array $lines;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FormulaVersionService::class);
        $this->formula = Formula::factory()->create();
        $this->a = TradeMaterial::factory()->create();
        $this->b = TradeMaterial::factory()->create();
        $this->lines = [
            ['trade_material_id' => $this->a->id, 'percent_ww' => '10.50', 'phase' => 'A'],
            ['trade_material_id' => $this->b->id, 'percent_ww' => null, 'is_qs' => true],
        ];
    }

    public function test_it_creates_version_1_as_a_draft_with_ordered_lines_and_a_hash(): void
    {
        $user = User::factory()->create();

        $v1 = $this->service->createVersion($this->formula, $this->lines, null, $user);

        $this->assertSame(1, $v1->version);
        $this->assertSame(FormulaStatus::Draft, $v1->status);
        $this->assertNull($v1->reason);
        $this->assertNull($v1->parent_version_id);
        $this->assertSame($user->id, $v1->created_by);
        $this->assertSame([1, 2], $v1->lines->pluck('line_no')->all());
        $this->assertSame('10.50', $v1->lines[0]->percent_ww);
        $this->assertTrue($v1->lines[1]->is_qs);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $v1->hash);
        $this->assertTrue($v1->verifyHash());
    }

    public function test_it_always_inserts_a_new_version_and_leaves_the_old_one_untouched(): void
    {
        $v1 = $this->service->createVersion($this->formula, $this->lines, null);
        $before = DB::table('formula_versions')->where('id', $v1->id)->first();
        $beforeLines = DB::table('formula_lines')->where('formula_version_id', $v1->id)->orderBy('line_no')->get();

        $changed = $this->lines;
        $changed[0]['percent_ww'] = '12';
        $v2 = $this->service->createVersion($this->formula, $changed, 'Raised A for viscosity');

        $this->assertSame(2, $v2->version);
        $this->assertSame($v1->id, $v2->parent_version_id);
        $this->assertSame('Raised A for viscosity', $v2->reason);
        $this->assertNotSame($v1->hash, $v2->hash);
        $this->assertSame(2, FormulaVersion::count());
        $this->assertEquals($before, DB::table('formula_versions')->where('id', $v1->id)->first());
        $this->assertEquals($beforeLines, DB::table('formula_lines')->where('formula_version_id', $v1->id)->orderBy('line_no')->get());
    }

    public function test_it_gives_equal_content_an_equal_hash_regardless_of_decimal_formatting(): void
    {
        $other = Formula::factory()->create();
        $v1 = $this->service->createVersion($this->formula, $this->lines, null);

        $same = $this->lines;
        $same[0]['percent_ww'] = '10.5';
        $v2 = $this->service->createVersion($other, $same, null);

        $this->assertSame($v1->hash, $v2->hash);
    }

    public function test_it_detects_lines_that_no_longer_match_the_recorded_hash(): void
    {
        $v1 = $this->service->createVersion($this->formula, $this->lines, null);
        DB::table('formula_lines')->where('formula_version_id', $v1->id)->where('line_no', 1)->update(['percent_ww' => '99']);

        $this->assertFalse($v1->fresh()->verifyHash());
    }

    /** @return array<string, array{0: string|null}> */
    public static function missingReasons(): array
    {
        return ['null' => [null], 'empty' => [''], 'blank' => ['   ']];
    }

    #[DataProvider('missingReasons')]
    public function test_it_requires_a_reason_from_the_second_version_on(?string $reason): void
    {
        $this->service->createVersion($this->formula, $this->lines, null);

        $this->assertThrowsException(InvalidFormulaVersionException::class, fn () => $this->service->createVersion($this->formula, $this->lines, $reason));
        $this->assertSame(1, FormulaVersion::count());
    }

    public function test_it_words_the_reason_rule_from_the_lang_files(): void
    {
        $this->service->createVersion($this->formula, $this->lines, null);

        try {
            $this->service->createVersion($this->formula, $this->lines, null);
            $this->fail('Expected InvalidFormulaVersionException.');
        } catch (InvalidFormulaVersionException $exception) {
            $this->assertSame(__('exceptions.formula_version_reason_required'), $exception->getMessage());
        }
    }

    public function test_it_rejects_an_empty_line_list(): void
    {
        $this->assertThrowsException(InvalidFormulaVersionException::class, fn () => $this->service->createVersion($this->formula, [], null));
    }

    public function test_it_rejects_float_percentages_and_a_missing_percentage_on_a_non_qs_line(): void
    {
        $this->assertThrowsException(InvalidArgumentException::class, fn () => $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => 10.5]], null));
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => null]], null)), 'formula_lines_percent_check');
        $this->assertSame(0, FormulaVersion::count());
    }

    public function test_it_rolls_back_the_whole_version_when_a_line_is_invalid(): void
    {
        // No foreign key on the encrypted trade_material_id (U-02): the action refuses an unknown material itself.
        $this->assertThrowsException(InvalidFormulaVersionException::class, fn () => $this->service->createVersion($this->formula, [
            ['trade_material_id' => $this->a->id, 'percent_ww' => '10'],
            ['trade_material_id' => fake()->uuid(), 'percent_ww' => '5'],
        ], null));

        $this->assertSame(0, FormulaVersion::count());
    }

    public function test_it_enforces_unique_version_numbers_and_the_reason_rule_in_the_database(): void
    {
        $v1 = $this->service->createVersion($this->formula, $this->lines, null);

        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => FormulaVersion::factory()->create(['formula_id' => $this->formula->id, 'version' => 1])));
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => FormulaVersion::factory()->create(['formula_id' => $this->formula->id, 'version' => 2, 'reason' => null])), 'formula_versions_reason_check');
        $this->assertSame(1, $v1->fresh()->version);
    }

    public function test_it_numbers_versions_per_formula(): void
    {
        $other = Formula::factory()->create();
        $this->service->createVersion($this->formula, $this->lines, null);

        $this->assertSame(1, $this->service->createVersion($other, $this->lines, null)->version);
    }
}
