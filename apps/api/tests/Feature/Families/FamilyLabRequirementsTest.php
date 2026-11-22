<?php

namespace Tests\Feature\Families;

use App\Models\Formula;
use App\Models\ProductFamily;
use App\Services\Lab\LabCoverageService;
use Database\Seeders\ProductFamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The measurements a formula needs before approval follow its family; the owner's explicit config still wins. */
class FamilyLabRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private function required(Formula $formula): array
    {
        return app(LabCoverageService::class)->requiredFor($formula->fresh());
    }

    public function test_a_formula_of_a_family_needs_what_the_family_names_that_the_lab_can_record(): void
    {
        $this->seed(ProductFamilySeeder::class);
        $dish = Formula::factory()->create(['product_type' => 'dish_liquid', 'product_family_id' => ProductFamily::query()->where('code', 'dish_liquid')->value('id')]);
        // Bathroom cleaner also names corrosion, which the lab loop cannot record yet: it is not demanded.
        $bathroom = Formula::factory()->create(['product_type' => 'bathroom_cleaner', 'product_family_id' => ProductFamily::query()->where('code', 'bathroom_cleaner')->value('id')]);
        // Laundry powder names only measurements the lab cannot record yet.
        $powder = Formula::factory()->create(['product_type' => 'laundry_powder', 'product_family_id' => ProductFamily::query()->where('code', 'laundry_powder')->value('id')]);

        $this->assertSame(['viscosity', 'ph', 'cloud_point'], $this->required($dish));
        $this->assertSame(['ph'], $this->required($bathroom));
        $this->assertSame([], $this->required($powder));
    }

    public function test_a_formula_without_a_family_keeps_the_default_set(): void
    {
        $this->assertSame(['ph', 'density', 'viscosity'], $this->required(Formula::factory()->create()));
    }

    public function test_the_owners_explicit_set_for_a_product_type_wins_over_the_family(): void
    {
        $this->seed(ProductFamilySeeder::class);
        config(['lab.required_measurements.by_product_type' => ['dish_liquid' => ['density']]]);
        $dish = Formula::factory()->create(['product_type' => 'dish_liquid', 'product_family_id' => ProductFamily::query()->where('code', 'dish_liquid')->value('id')]);

        $this->assertSame(['density'], $this->required($dish));
    }
}
