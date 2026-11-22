<?php

namespace Tests\Feature\Materials;

use App\Enums\FunctionalClass;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M-02: functional classes of a material, stored, validated, filterable in the library. */
class MaterialFunctionalClassTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return ['trade_name' => 'Cetyl alcohol', 'status' => 'draft', 'is_mixture' => true, ...$overrides];
    }

    /** @return list<string> */
    private function names(array $query): array
    {
        $names = [];
        $this->get(route('materials.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$names): void {
            $names = array_column($page->toArray()['props']['list']['rows'], 'trade_name');
        });
        sort($names);

        return $names;
    }

    public function test_it_creates_a_material_with_several_functional_classes(): void
    {
        $this->post('/materials', $this->payload(['functional_classes' => ['emollient', 'thickener']]))->assertSessionHasNoErrors();

        $this->assertSame(['emollient', 'thickener'], TradeMaterial::query()->firstOrFail()->functional_classes);
    }

    public function test_a_material_without_classes_has_an_empty_list(): void
    {
        $this->post('/materials', $this->payload())->assertSessionHasNoErrors();

        $this->assertSame([], TradeMaterial::query()->firstOrFail()->functional_classes ?? []);
    }

    public function test_it_updates_the_classes_and_audits_the_change(): void
    {
        $material = TradeMaterial::factory()->mixture()->create(['functional_classes' => ['humectant']]);

        $this->put(route('materials.update', $material), $this->payload(['functional_classes' => ['humectant', 'occlusive']]))->assertSessionHasNoErrors();

        $this->assertSame(['humectant', 'occlusive'], $material->fresh()->functional_classes);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'trade_materials', 'record_id' => $material->id, 'action' => 'update']);
    }

    public function test_it_refuses_an_unknown_or_repeated_class(): void
    {
        $this->post('/materials', $this->payload(['functional_classes' => ['moisturiser']]))->assertSessionHasErrors('functional_classes.0');
        $this->post('/materials', $this->payload(['functional_classes' => ['active', 'active']]))->assertSessionHasErrors('functional_classes.0');
        $this->assertDatabaseCount('trade_materials', 0);
    }

    public function test_the_library_filters_by_functional_class_and_lists_them(): void
    {
        TradeMaterial::factory()->mixture()->create(['trade_name' => 'Glycerin', 'functional_classes' => ['humectant']]);
        TradeMaterial::factory()->mixture()->create(['trade_name' => 'Shea butter', 'functional_classes' => ['emollient', 'occlusive']]);
        TradeMaterial::factory()->mixture()->create(['trade_name' => 'Unclassified']);

        $this->assertSame(['Shea butter'], $this->names(['functional_class' => 'occlusive']));
        $this->assertSame(['Glycerin'], $this->names(['functional_class' => 'humectant']));
        $this->assertSame(['Glycerin', 'Shea butter', 'Unclassified'], $this->names([]));
        $this->assertSame([], $this->names(['functional_class' => 'fragrance']));

        $this->get(route('materials.index'))->assertInertia(fn (Assert $page) => $page
            ->where('functionalClassOptions', FunctionalClass::options())
            ->where('list.rows.1.functional_classes', ['emollient', 'occlusive']));
    }

    public function test_the_material_page_carries_the_classes_and_the_options(): void
    {
        $material = TradeMaterial::factory()->mixture()->create(['functional_classes' => ['preservative']]);

        $this->get(route('materials.show', $material))->assertInertia(fn (Assert $page) => $page
            ->where('material.functional_classes', ['preservative'])
            ->has('functionalClassOptions', count(FunctionalClass::cases())));
    }

    public function test_every_class_has_a_label_in_both_languages(): void
    {
        $this->assertCount(8, FunctionalClass::cases());
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (FunctionalClass::cases() as $case) {
                $this->assertNotSame('enums.functional_class.'.$case->value, $case->label());
            }
        }
    }
}
