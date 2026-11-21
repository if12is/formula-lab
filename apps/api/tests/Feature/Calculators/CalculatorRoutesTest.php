<?php

namespace Tests\Feature\Calculators;

use App\Enums\Confidence;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TradeMaterial;
use App\Support\Translations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The calculators (S-07, S-14): the index, one page per calculator, their translated props and the material lookup. */
class CalculatorRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function calculators(): array
    {
        return [
            'saponification' => ['saponification', 'Calculators/Saponification'],
            'neutralization' => ['neutralization', 'Calculators/Neutralization'],
            'dilution' => ['dilution', 'Calculators/Dilution'],
            'hlb' => ['hlb', 'Calculators/Hlb'],
            'litre-kilo' => ['litre-kilo', 'Calculators/LitreKilo'],
            'percent-from-weight' => ['percent-from-weight', 'Calculators/PercentFromWeight'],
            'fragrance' => ['fragrance', 'Calculators/Fragrance'],
        ];
    }

    public function test_the_index_replaces_the_placeholder_and_lists_every_calculator_by_group(): void
    {
        $this->get('/calculators')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Calculators/Index')
            ->has('groups', 3)
            ->where('groups.0.group', 'chemistry')
            ->has('groups.0.items', 4)
            ->where('groups.0.items.0.key', 'saponification')
            ->where('groups.0.items.0.href', '/calculators/saponification')
            ->where('groups.1.group', 'conversion')
            ->where('groups.2.items.0.key', 'fragrance'));

        $this->assertNotContains('calculators', config('ui.placeholder_sections'));
    }

    #[DataProvider('calculators')]
    public function test_each_calculator_renders_its_page_with_the_lookups_its_inputs_need(string $key, string $component): void
    {
        $this->get("/calculators/{$key}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('calculator', $key)
            ->where('urls.materials', route('calculators.materials.search', [], false))
            ->where('urls.sources', route('sources.search', [], false))
            ->where('urls.sourceStore', route('sources.store', [], false))
            ->has('confidenceOptions', count(Confidence::cases()))
            ->has('sourceKindOptions'));
    }

    public function test_an_unknown_calculator_is_not_found(): void
    {
        $this->get('/calculators/not-a-calculator')->assertNotFound();
    }

    #[DataProvider('calculators')]
    public function test_each_calculator_has_a_title_and_description_in_both_languages_and_arrives_translated(string $key, string $component): void
    {
        foreach (['ar', 'en'] as $locale) {
            $dictionary = Translations::for($locale);

            foreach (["calculators.items.{$key}.title", "calculators.items.{$key}.description", "calculators.{$key}.results.".$this->firstResultKey($key)] as $textKey) {
                $this->assertArrayHasKey($textKey, $dictionary, "[{$textKey}] missing in {$locale}");
            }
        }

        $this->get("/calculators/{$key}")->assertInertia(fn (Assert $page) => $page
            ->where('translations', fn ($translations) => isset($translations["calculators.items.{$key}.title"], $translations['engine.unknown_reason'], $translations['calculators.explain.title'])));
    }

    public function test_the_texts_are_arabic_by_default(): void
    {
        $this->get('/calculators/litre-kilo')->assertInertia(fn (Assert $page) => $page
            ->where('locale.current', 'ar')
            ->where('translations', fn ($translations) => $translations['calculators.items.litre-kilo.title'] === 'لتر وكيلو'));
    }

    public function test_the_texts_follow_the_chosen_language(): void
    {
        $this->withCookie(config('ui.cookies.locale'), 'en')->get('/calculators/hlb')->assertInertia(fn (Assert $page) => $page
            ->where('locale.current', 'en')
            ->where('translations', fn ($translations) => $translations['calculators.items.hlb.title'] === 'HLB'));
    }

    public function test_every_calculator_of_the_config_has_a_card_icon_in_the_frontend(): void
    {
        $icons = (string) file_get_contents(resource_path('js/config/calculators.js'));

        foreach (array_keys(config('calculators.items')) as $key) {
            $this->assertMatchesRegularExpression("/['\"]?{$key}['\"]?:\\s*'ph:[a-z-]+'/", $icons, "no card icon for [{$key}]");
        }
    }

    public function test_every_calculator_page_exists_as_a_vue_file(): void
    {
        foreach (config('calculators.items') as $key => $item) {
            $this->assertFileExists(resource_path("js/pages/{$item['component']}.vue"), "page of [{$key}] is missing");
        }
    }

    public function test_the_unit_labels_cover_every_unit_the_calculators_use(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $units = Arr::dot(require lang_path("{$locale}/calculators.php"));

            foreach (['g', 'kg', 'mg', 'mL', 'L', '%w/w', '%v/v', 'mgKOH/g', 'gNaOH/g', 'g/mL', 'kg/L', 'HLB', 'EGP'] as $unit) {
                $this->assertArrayHasKey("units.{$unit}", $units, "unit [{$unit}] has no label in {$locale}");
            }
        }
    }

    public function test_the_material_lookup_carries_the_registered_property_values_with_their_sources(): void
    {
        $substance = Substance::factory()->create();
        $material = TradeMaterial::factory()->for($substance)->create(['trade_name' => 'Calc Olive Oil']);
        $source = Source::factory()->create(['title' => 'Oil data sheet']);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $material->id, 'property' => 'sap', 'value' => '190', 'unit' => 'mgKOH/g', 'valid_from' => '2026-03-01', 'source_id' => $source->id, 'confidence' => Confidence::L2SupplierTds]);
        PropertyValue::factory()->create(['entity_type' => 'substance', 'entity_id' => $substance->id, 'property' => 'density', 'value' => '0.91', 'unit' => 'g/mL', 'valid_from' => '2026-02-01', 'source_id' => $source->id]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $material->id, 'property' => 'not_registered', 'value' => '1', 'unit' => 'x', 'source_id' => $source->id]);

        $response = $this->getJson('/calculators/materials?q=Calc Olive')->assertOk();

        $response->assertJsonCount(1)
            ->assertJsonPath('0.label', 'Calc Olive Oil')
            ->assertJsonPath('0.properties.1.property', 'sap')
            ->assertJsonPath('0.properties.1.value', '190')
            ->assertJsonPath('0.properties.1.source_title', 'Oil data sheet')
            ->assertJsonPath('0.properties.1.confidence', 'L2_supplier_tds')
            ->assertJsonPath('0.properties.1.valid_from', '2026-03-01');
        $this->assertCount(2, $response->json('0.properties'));
        $this->assertSame(['density', 'sap'], array_column($response->json('0.properties'), 'property'));
    }

    public function test_the_source_lookup_carries_the_date_that_dates_a_value_typed_against_it(): void
    {
        Source::factory()->create(['title' => 'Dated sheet', 'retrieved_at' => '2026-03-05']);

        $this->getJson('/sources/search?q=Dated')->assertOk()
            ->assertJsonPath('0.retrieved_at', '2026-03-05');
    }

    private function firstResultKey(string $key): string
    {
        return ['saponification' => 'lye_used', 'neutralization' => 'reagent_mass', 'dilution' => 'active_pct', 'hlb' => 'required_hlb', 'litre-kilo' => 'volume', 'percent-from-weight' => 'percent', 'fragrance' => 'oil_mass'][$key];
    }
}
