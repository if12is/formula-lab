<?php

namespace Tests\Feature\Materials;

use App\Models\Source;
use App\Models\TradeMaterial;
use App\Support\PropertyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The registry of property keys (config/properties.php): one list for the material page, the property form and the calculators. */
class PropertyRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registry_key_has_a_label_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = Arr::dot(require lang_path("{$locale}/properties.php"));

            foreach (config('properties') as $key => $property) {
                $this->assertSame("properties.{$key}", $property['label_key'], "label_key of [{$key}] is not properties.{$key}");
                $this->assertArrayHasKey($key, $texts, "lang/{$locale}/properties.php lacks [{$key}]");
                $this->assertNotSame('', trim($texts[$key]));
            }
        }
    }

    public function test_the_language_files_name_only_registered_keys(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = array_keys(Arr::dot(require lang_path("{$locale}/properties.php")));

            $this->assertEqualsCanonicalizing(PropertyRegistry::keys(), $texts, "lang/{$locale}/properties.php and config/properties.php differ");
        }
    }

    public function test_every_key_has_units_and_no_unit_repeats(): void
    {
        foreach (config('properties') as $key => $property) {
            $this->assertNotSame([], $property['units'], "[{$key}] has no unit");
            $this->assertSame($property['units'], array_values(array_unique($property['units'])));
        }
    }

    public function test_the_registry_is_shared_with_every_page_with_its_label_keys(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('propertyRegistry', count(config('properties')))
            ->where('propertyRegistry.0.value', 'density')
            ->where('propertyRegistry.0.labelKey', 'properties.density')
            ->where('propertyRegistry.0.units', ['g/mL', 'kg/L'])
            ->where('translations', fn ($translations) => isset($translations['properties.density'], $translations['properties.active_matter'])));
    }

    public function test_the_material_page_no_longer_offers_free_text_names(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->get('/materials/'.$material->id)->assertInertia(fn (Assert $page) => $page->missing('propertyNames'));
    }

    public function test_a_property_outside_the_registry_is_refused(): void
    {
        $material = TradeMaterial::factory()->create();
        $source = Source::factory()->create();

        $this->post("/materials/{$material->id}/properties", $this->payload($source, ['property' => 'made_up']))
            ->assertSessionHasErrors('property');
    }

    public function test_a_unit_the_registry_does_not_allow_for_the_property_is_refused(): void
    {
        $material = TradeMaterial::factory()->create();
        $source = Source::factory()->create();

        $this->post("/materials/{$material->id}/properties", $this->payload($source, ['property' => 'density', 'unit' => 'mgKOH/g']))
            ->assertSessionHasErrors('unit');
    }

    public function test_a_registered_property_with_one_of_its_units_is_stored(): void
    {
        $material = TradeMaterial::factory()->create();
        $source = Source::factory()->create();

        $this->post("/materials/{$material->id}/properties", $this->payload($source, ['property' => 'sap', 'value' => '190', 'unit' => 'mgKOH/g']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('property_values', ['entity_id' => $material->id, 'property' => 'sap', 'value' => '190', 'unit' => 'mgKOH/g']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Source $source, array $overrides): array
    {
        return [
            'property' => 'density', 'value' => '1.04', 'min' => null, 'max' => null, 'unit' => 'g/mL',
            'source_id' => $source->id, 'confidence' => 'L2_supplier_tds', 'valid_from' => '2026-03-01',
            ...$overrides,
        ];
    }
}
