<?php

namespace Tests\Feature\Data;

use App\Enums\FormulaStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovedFormulaImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function approvedVersionWithLine(): FormulaVersion
    {
        $version = FormulaVersion::factory()->create();
        FormulaLine::factory()->create(['formula_version_id' => $version->id]);
        $version->update(['status' => FormulaStatus::Approved]);

        return $version->fresh();
    }

    public function test_it_allows_moving_a_draft_through_lab_testing_to_approved(): void
    {
        $version = FormulaVersion::factory()->create();

        $version->update(['status' => FormulaStatus::LabTesting]);
        $version->update(['status' => FormulaStatus::Approved]);

        $this->assertTrue($version->fresh()->isApproved());
    }

    public function test_it_throws_a_domain_exception_on_model_update_or_delete(): void
    {
        $version = $this->approvedVersionWithLine();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $version->update(['reason' => 'x']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $version->update(['status' => FormulaStatus::Draft]));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $version->delete());
    }

    public function test_it_words_the_immutable_exception_from_the_lang_files(): void
    {
        $version = $this->approvedVersionWithLine();

        try {
            $version->update(['reason' => 'x']);
            $this->fail('Expected ImmutableRecordException.');
        } catch (ImmutableRecordException $exception) {
            $this->assertSame(__('exceptions.approved_formula_version_immutable', ['version' => $version->id]), $exception->getMessage());
        }
    }

    public function test_it_rejects_update_and_delete_at_the_database_level(): void
    {
        $version = $this->approvedVersionWithLine();

        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_versions')->where('id', $version->id)->update(['status' => 'draft'])), 'immutable');
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_versions')->where('id', $version->id)->update(['hash' => str_repeat('0', 64)])), 'immutable');
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_versions')->where('id', $version->id)->delete()), 'immutable');

        $this->assertSame(FormulaStatus::Approved, $version->fresh()->status);
    }

    public function test_it_protects_the_lines_of_an_approved_version_in_the_model_and_in_the_database(): void
    {
        $version = $this->approvedVersionWithLine();
        $line = $version->lines()->first();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $line->update(['percent_ww' => '50']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $line->delete());
        $this->assertThrowsException(ImmutableRecordException::class, fn () => FormulaLine::factory()->create(['formula_version_id' => $version->id, 'line_no' => 2]));
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_lines')->where('id', $line->id)->update(['percent_ww' => '50'])), 'immutable');
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_lines')->where('id', $line->id)->delete()), 'immutable');
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_lines')->insert([
            'id' => fake()->uuid(), 'formula_version_id' => $version->id, 'line_no' => 9,
            'trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => '1', 'is_qs' => false,
        ])), 'immutable');
    }

    public function test_it_leaves_draft_versions_and_their_lines_editable(): void
    {
        $version = FormulaVersion::factory()->create();
        $line = FormulaLine::factory()->create(['formula_version_id' => $version->id]);

        $line->update(['percent_ww' => '20']);
        $version->update(['reason' => 'fine']);
        $line->delete();

        $this->assertSame('fine', $version->fresh()->reason);
        $this->assertSame(0, FormulaLine::count());
    }

    public function test_it_lets_a_new_version_be_created_from_an_approved_one(): void
    {
        $approved = $this->approvedVersionWithLine();
        $formula = $approved->formula;

        $next = app(FormulaVersionService::class)->createVersion($formula, [
            ['trade_material_id' => $approved->lines->first()->trade_material_id, 'percent_ww' => '11'],
        ], 'Adjusted after review');

        $this->assertSame(2, $next->version);
        $this->assertSame(FormulaStatus::Draft, $next->status);
        $this->assertTrue($approved->fresh()->isApproved());
    }
}
