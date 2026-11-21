<?php

namespace Tests\Feature\Actions;

use App\Actions\Formula\CreateFormulaVersionAction;
use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreateFormulaVersionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_inserts_a_draft_version_with_ordered_lines_and_its_hash(): void
    {
        $formula = Formula::factory()->create();
        $user = User::factory()->create();
        $a = TradeMaterial::factory()->create();
        $b = TradeMaterial::factory()->create();

        $version = app(CreateFormulaVersionAction::class)($formula, [
            ['trade_material_id' => $a->id, 'percent_ww' => '10.50', 'phase' => 'A'],
            ['trade_material_id' => $b->id, 'is_qs' => true],
        ], 1, null, null, $user);

        $this->assertSame(1, $version->version);
        $this->assertSame(FormulaStatus::Draft, $version->status);
        $this->assertSame($user->id, $version->created_by);
        $this->assertSame([1, 2], $version->lines->pluck('line_no')->all());
        $this->assertTrue($version->verifyHash());
    }

    public function test_it_links_the_parent_and_keeps_the_reason_from_the_second_version_on(): void
    {
        $formula = Formula::factory()->create();
        $material = TradeMaterial::factory()->create();
        $lines = [['trade_material_id' => $material->id, 'percent_ww' => '5']];
        $action = app(CreateFormulaVersionAction::class);

        $first = $action($formula, $lines, 1, null, 'ignored for version 1');
        $second = $action($formula, $lines, 2, $first, 'Because');

        $this->assertNull($first->reason);
        $this->assertSame($first->id, $second->parent_version_id);
        $this->assertSame('Because', $second->reason);
    }

    public function test_it_rejects_float_percentages_before_writing_anything(): void
    {
        $formula = Formula::factory()->create();
        $material = TradeMaterial::factory()->create();

        $this->assertThrowsException(InvalidArgumentException::class, fn () => app(CreateFormulaVersionAction::class)(
            $formula, [['trade_material_id' => $material->id, 'percent_ww' => 1.5]], 1, null, null,
        ));
        $this->assertSame(0, FormulaVersion::count());
    }

    public function test_it_rolls_back_the_version_when_a_line_is_refused_by_the_database(): void
    {
        $formula = Formula::factory()->create();

        $this->assertThrowsException(QueryException::class, fn () => app(CreateFormulaVersionAction::class)(
            $formula, [['trade_material_id' => fake()->uuid(), 'percent_ww' => '5']], 1, null, null,
        ));
        $this->assertSame(0, FormulaVersion::count());
    }
}
