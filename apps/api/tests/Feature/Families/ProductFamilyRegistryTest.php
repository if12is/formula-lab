<?php

namespace Tests\Feature\Families;

use App\Actions\Family\SyncProductFamiliesAction;
use App\Enums\DataVerification;
use App\Enums\LabProperty;
use App\Enums\Regulator;
use App\Enums\TemplateVerification;
use App\Exceptions\Domain\InvalidFamilyDataException;
use App\Models\FormulaTemplate;
use App\Models\ProductFamily;
use App\Support\Families\FamilyData;
use Database\Seeders\ProductFamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** K-01 / K-02 / K-06: the registry is data, loaded as the sources state it, never guessed. */
class ProductFamilyRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_loads_every_family_and_its_template_and_is_idempotent(): void
    {
        $this->seed(ProductFamilySeeder::class);
        $this->seed(ProductFamilySeeder::class);

        $this->assertSame(35, ProductFamily::query()->count());
        $this->assertSame(30, FormulaTemplate::query()->count());
        $this->assertSame(
            ['dish_liquid', 'laundry_powder', 'laundry_liquid', 'floor_cleaner', 'glass_cleaner', 'bathroom_cleaner', 'degreaser', 'fabric_softener', 'laundry_fragrance', 'shampoo', 'conditioner', 'hand_soap', 'shower_gel', 'bar_soap', 'face_wash', 'alcohol_disinfectant', 'quat_disinfectant', 'room_spray', 'aerosol_air_freshener', 'reed_diffuser', 'candle_wax_melt', 'gel_air_freshener', 'car_air_freshener', 'fabric_air_freshener', 'lotion', 'cream', 'face_cream', 'body_butter', 'moisturising_gel', 'hand_cream', 'hair_moisturiser', 'sunscreen', 'mask', 'cleansing_cream', 'cleansing_foam'],
            ProductFamily::query()->orderBy('position')->pluck('code')->all(),
        );
    }

    public function test_the_air_care_families_state_what_the_research_states_and_nothing_more(): void
    {
        $this->seed(ProductFamilySeeder::class);
        $family = fn (string $code): ProductFamily => ProductFamily::query()->where('code', $code)->sole();

        $this->assertSame('10A', $family('reed_diffuser')->ifra_category);
        $this->assertSame('12', $family('candle_wax_melt')->ifra_category);
        $this->assertSame('10B', $family('aerosol_air_freshener')->ifra_category);
        $this->assertSame(['fragrance', 'aerosol_fill'], $family('aerosol_air_freshener')->required_calculations);
        $this->assertSame('aerosol_filling_line', $family('aerosol_air_freshener')->notes_key);
        foreach (['gel_air_freshener', 'car_air_freshener'] as $code) {
            $this->assertNull($family($code)->ifra_category, "{$code}: the research says 12 or by type, so none is stated");
            $this->assertSame('ifra_12_or_by_type', $family($code)->notes_key);
        }
        $this->assertSame(7, ProductFamily::query()->where('group', 'air_care')->count());
    }

    public function test_regulators_are_the_ones_the_research_names_and_nothing_else_is_stated(): void
    {
        $this->seed(ProductFamilySeeder::class);

        foreach (ProductFamily::query()->get() as $family) {
            $this->assertSame(in_array($family->group, ['detergents', 'air_care'], true) ? Regulator::Eos : Regulator::Eda, $family->regulator, $family->code);
            $this->assertNull($family->tax_regime, "{$family->code} has a tax regime nobody stated");
            $this->assertSame(DataVerification::Pending, $family->verification, "{$family->code} claims a check nobody made");
        }

        $this->assertSame('10B', ProductFamily::query()->where('code', 'glass_cleaner')->value('ifra_category'));
        $this->assertSame('9', ProductFamily::query()->where('code', 'shampoo')->value('ifra_category'));
    }

    public function test_dish_liquid_needs_what_the_research_table_lists(): void
    {
        $this->seed(ProductFamilySeeder::class);
        $dish = ProductFamily::query()->where('code', 'dish_liquid')->sole();

        $this->assertSame(['active_matter', 'neutralization', 'salt_curve', 'litre_kilo'], $dish->required_calculations);
        $this->assertSame(['viscosity', 'ph', 'cloud_point'], $dish->measured_properties);
    }

    public function test_every_vocabulary_entry_points_at_something_that_exists(): void
    {
        $data = app(FamilyData::class);

        foreach ($data->calculations() as $calculation) {
            if ($calculation['calculatorKey'] !== null) {
                $this->assertArrayHasKey($calculation['calculatorKey'], config('calculators.items'), "{$calculation['id']} calculator");
            }
        }

        foreach ($data->measuredProperties() as $property) {
            if ($property['labProperty'] !== null) {
                $this->assertNotNull(LabProperty::tryFrom($property['labProperty']), "{$property['id']} lab property");
            }
        }
    }

    public function test_every_family_template_and_vocabulary_word_has_a_text_in_both_languages(): void
    {
        $data = app(FamilyData::class);

        foreach (['ar', 'en'] as $locale) {
            $texts = Arr::dot(require lang_path("{$locale}/families.php"));
            $need = [];

            foreach ($data->calculations() as $calculation) {
                $need[] = "calculations.{$calculation['id']}";
            }
            foreach ($data->measuredProperties() as $property) {
                $need[] = "measured.{$property['id']}";
            }
            foreach ($data->roles() as $role) {
                $need[] = "roles.{$role}";
            }
            foreach ($data->registry()['vocabulary']['groups'] as $group) {
                $need[] = "groups.{$group}";
            }
            foreach (Regulator::values() as $value) {
                $need[] = "regulators.{$value}";
            }
            foreach (TemplateVerification::values() as $value) {
                $need[] = "verification.{$value}";
            }
            foreach ($data->registry()['families'] as $family) {
                if (isset($family['notes'])) {
                    $need[] = "notes.{$family['notes']}";
                }
            }

            foreach ($need as $key) {
                $this->assertNotSame('', trim($texts[$key] ?? ''), "{$locale} families.{$key}");
            }
        }
    }

    public function test_every_reason_key_the_engine_can_emit_for_families_has_a_text_in_both_languages(): void
    {
        $keys = [];
        foreach (glob(base_path('../../packages/engine/src/families/*.ts')) as $file) {
            if (str_contains($file, '.test.')) {
                continue;
            }
            preg_match_all('/[\'"]families\.([a-z0-9_]+)[\'"]/', (string) file_get_contents($file), $matches);
            array_push($keys, ...$matches[1]);
        }
        $keys = array_unique($keys);

        $this->assertContains('template_line_material_missing', $keys);
        $this->assertContains('template_compliance_failed', $keys);

        foreach (['ar', 'en'] as $locale) {
            $texts = Arr::dot(require lang_path("{$locale}/families.php"));

            foreach ($keys as $key) {
                $this->assertNotSame('', trim($texts["reasons.{$key}"] ?? ''), "{$locale} families.reasons.{$key}");
            }
        }
    }

    public function test_a_file_the_engine_rejects_is_never_loaded(): void
    {
        Process::fake(['*' => Process::result('{"ok":false,"missing":[{"property":"families[0].regulator","reason":"families.regulator_unknown"}]}')]);

        try {
            app(SyncProductFamiliesAction::class)();
            $this->fail('The sync should have refused the files.');
        } catch (InvalidFamilyDataException $exception) {
            $this->assertStringContainsString('families[0].regulator', $exception->getMessage());
            $this->assertStringContainsString(__('families.reasons.regulator_unknown'), $exception->getMessage());
        }

        $this->assertSame(0, ProductFamily::query()->count());
    }

    public function test_a_disinfectant_flag_the_engine_rejects_is_worded_from_the_engine_texts_and_nothing_is_loaded(): void
    {
        Process::fake(['*' => Process::result('{"ok":false,"missing":[{"property":"claims[1].code","reason":"disinfectant.flag_claim_duplicate"}]}')]);

        try {
            app(SyncProductFamiliesAction::class)();
            $this->fail('The sync should have refused the files.');
        } catch (InvalidFamilyDataException $exception) {
            $this->assertStringContainsString('claims[1].code', $exception->getMessage());
            $this->assertStringContainsString(__('engine.disinfectant.flag_claim_duplicate'), $exception->getMessage());
        }

        $this->assertSame(0, ProductFamily::query()->count());
    }
}
