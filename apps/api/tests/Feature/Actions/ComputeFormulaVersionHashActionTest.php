<?php

namespace Tests\Feature\Actions;

use App\Actions\Formula\ComputeFormulaVersionHashAction;
use App\Models\FormulaLine;
use Tests\TestCase;

class ComputeFormulaVersionHashActionTest extends TestCase
{
    /** @return list<FormulaLine> */
    private function lines(string $percent): array
    {
        return [
            new FormulaLine(['line_no' => 1, 'trade_material_id' => 'm-1', 'percent_ww' => $percent, 'is_qs' => false, 'phase' => 'A']),
            new FormulaLine(['line_no' => 2, 'trade_material_id' => 'm-2', 'percent_ww' => null, 'is_qs' => true]),
        ];
    }

    public function test_it_returns_a_sha256_of_the_canonical_lines(): void
    {
        $hash = app(ComputeFormulaVersionHashAction::class)($this->lines('10.5'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_it_ignores_decimal_formatting(): void
    {
        $action = app(ComputeFormulaVersionHashAction::class);

        $this->assertSame($action($this->lines('10.5')), $action($this->lines('10.50')));
    }

    public function test_it_changes_when_the_content_changes(): void
    {
        $action = app(ComputeFormulaVersionHashAction::class);

        $this->assertNotSame($action($this->lines('10.5')), $action($this->lines('10.6')));
    }
}
