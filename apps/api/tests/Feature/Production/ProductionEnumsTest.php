<?php

namespace Tests\Feature\Production;

use App\Enums\Production\BatchLineKind;
use App\Enums\Production\DeviationApprovalStatus;
use App\Enums\Production\DeviationKind;
use App\Enums\Production\DeviationVerdict;
use App\Enums\Production\ProcessStepKind;
use App\Enums\Production\ProductionBatchStatus;
use App\Enums\Production\ProductionOrderStatus;
use BackedEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every case of the production enums has a label in both languages (enums.<snake_name>.<value>), and the database accepts every value. */
class ProductionEnumsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: class-string<BackedEnum>}> */
    public static function enums(): array
    {
        return [
            'ProductionOrderStatus' => [ProductionOrderStatus::class],
            'ProductionBatchStatus' => [ProductionBatchStatus::class],
            'ProcessStepKind' => [ProcessStepKind::class],
            'DeviationKind' => [DeviationKind::class],
            'DeviationApprovalStatus' => [DeviationApprovalStatus::class],
            'DeviationVerdict' => [DeviationVerdict::class],
            'BatchLineKind' => [BatchLineKind::class],
        ];
    }

    /** @param  class-string<BackedEnum>  $enum */
    #[DataProvider('enums')]
    public function test_every_case_has_a_label_in_both_languages(string $enum): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ($enum::cases() as $case) {
                $key = 'enums.'.Str::snake(class_basename($enum)).'.'.$case->value;
                $this->assertNotSame($key, __($key), "{$key} has no text in {$locale}");
                $this->assertSame(__($key), $case->label());
            }
        }
    }

    public function test_the_step_kinds_the_database_accepts_are_exactly_the_enum(): void
    {
        $check = DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'formula_process_steps_kind_check'");

        foreach (ProcessStepKind::values() as $value) {
            $this->assertStringContainsString("'{$value}'", $check->def);
        }
    }
}
