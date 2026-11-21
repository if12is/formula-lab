<?php

namespace Tests\Feature\Enums;

use App\Enums\AuditAction;
use App\Enums\Confidence;
use App\Enums\FilterType;
use App\Enums\FormulaStatus;
use App\Enums\ProductTier;
use App\Enums\SourceKind;
use App\Enums\TradeMaterialStatus;
use BackedEnum;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnumLabelsTest extends TestCase
{
    /** @return array<string, array{0: class-string<BackedEnum>}> */
    public static function enums(): array
    {
        return [
            'AuditAction' => [AuditAction::class],
            'Confidence' => [Confidence::class],
            'FilterType' => [FilterType::class],
            'FormulaStatus' => [FormulaStatus::class],
            'ProductTier' => [ProductTier::class],
            'SourceKind' => [SourceKind::class],
            'TradeMaterialStatus' => [TradeMaterialStatus::class],
        ];
    }

    /** @param  class-string<BackedEnum>  $enum */
    #[DataProvider('enums')]
    public function test_every_case_has_a_label_in_both_languages(string $enum): void
    {
        $group = 'enums.'.Str::snake(class_basename($enum));

        foreach (['ar', 'en'] as $locale) {
            foreach ($enum::cases() as $case) {
                $key = "{$group}.{$case->value}";
                $label = __($key, [], $locale);

                $this->assertNotSame($key, $label, "missing [{$key}] in {$locale}");
            }
        }
    }

    /** @param  class-string<BackedEnum>  $enum */
    #[DataProvider('enums')]
    public function test_options_lists_value_and_translated_label_for_every_case(string $enum): void
    {
        $options = $enum::options();

        $this->assertCount(count($enum::cases()), $options);
        foreach ($enum::cases() as $index => $case) {
            $this->assertSame(['value' => $case->value, 'label' => $case->label()], $options[$index]);
        }
    }

    /** @param  class-string<BackedEnum>  $enum */
    #[DataProvider('enums')]
    public function test_values_lists_the_backing_values(string $enum): void
    {
        $this->assertSame(array_map(fn (BackedEnum $case) => $case->value, $enum::cases()), $enum::values());
    }

    public function test_labels_follow_the_active_locale(): void
    {
        $this->assertSame('مسودة', FormulaStatus::Draft->label());

        app()->setLocale('en');

        $this->assertSame('Draft', FormulaStatus::Draft->label());
        $this->assertSame(['value' => 'economy', 'label' => 'Economy'], ProductTier::options()[0]);
    }
}
