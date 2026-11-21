<?php

namespace Tests\Feature\Data;

use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaDiffService;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormulaDiffTest extends TestCase
{
    use RefreshDatabase;

    private FormulaVersionService $service;

    private FormulaDiffService $diff;

    private Formula $formula;

    private TradeMaterial $a;

    private TradeMaterial $b;

    private TradeMaterial $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FormulaVersionService::class);
        $this->diff = app(FormulaDiffService::class);
        $this->formula = Formula::factory()->create();
        [$this->a, $this->b, $this->c] = TradeMaterial::factory()->count(3)->create()->all();
    }

    public function test_it_reports_added_removed_and_changed_lines_plus_reason_and_status_changes(): void
    {
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

        $this->assertSame([], $result['added']);
        $this->assertCount(1, $result['removed']);
        $this->assertSame($this->c->id, $result['removed'][0]['tradeMaterialId']);
        $this->assertTrue($result['removed'][0]['isQs']);
        $this->assertSame([[
            'trade_material_id' => $this->a->id,
            'changes' => [
                'percent_ww' => ['from' => '10', 'to' => '12'],
                'phase' => ['from' => 'A', 'to' => 'B'],
            ],
        ]], $result['changed']);
        $this->assertSame([
            'reason' => ['from' => null, 'to' => 'Dropped C, raised A'],
            'status' => ['from' => 'draft', 'to' => 'lab_testing'],
        ], $result['meta']);
    }

    public function test_it_treats_a_material_swap_as_one_removed_plus_one_added_line(): void
    {
        $v1 = $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => '10']], null);
        $v2 = $this->service->createVersion($this->formula, [['trade_material_id' => $this->b->id, 'percent_ww' => '10']], 'Swap');

        $result = $this->diff->diff($v1, $v2);

        $this->assertSame($this->a->id, $result['removed'][0]['tradeMaterialId']);
        $this->assertSame($this->b->id, $result['added'][0]['tradeMaterialId']);
        $this->assertSame([], $result['changed']);
    }

    public function test_it_reports_no_differences_for_identical_content_and_ignores_decimal_formatting(): void
    {
        $v1 = $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => '10.0']], null);
        $v2 = $this->service->createVersion($this->formula, [['trade_material_id' => $this->a->id, 'percent_ww' => '10']], 'Same');

        $result = $this->diff->diff($v1, $v2);

        $this->assertSame([], $result['added']);
        $this->assertSame([], $result['removed']);
        $this->assertSame([], $result['changed']);
        $this->assertArrayHasKey('reason', $result['meta']);
        $this->assertArrayNotHasKey('status', $result['meta']);
    }

    public function test_it_pairs_repeated_materials_by_occurrence(): void
    {
        $v1 = $this->service->createVersion($this->formula, [
            ['trade_material_id' => $this->a->id, 'percent_ww' => '1', 'phase' => 'A'],
            ['trade_material_id' => $this->a->id, 'percent_ww' => '2', 'phase' => 'B'],
        ], null);
        $v2 = $this->service->createVersion($this->formula, [
            ['trade_material_id' => $this->a->id, 'percent_ww' => '1', 'phase' => 'A'],
            ['trade_material_id' => $this->a->id, 'percent_ww' => '3', 'phase' => 'B'],
        ], 'Change second');

        $result = $this->diff->diff($v1, $v2);

        $this->assertCount(1, $result['changed']);
        $this->assertSame(['percent_ww' => ['from' => '2', 'to' => '3']], $result['changed'][0]['changes']);
    }
}
