<?php

namespace Tests\Feature\Families;

use App\Models\FormulaTemplate;
use App\Models\ProductFamily;
use Database\Seeders\ProductFamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FamilyPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductFamilySeeder::class);
    }

    public function test_the_list_shows_every_family_and_the_filters_the_page_needs(): void
    {
        $this->get(route('families.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Families/Index')
            ->where('list.meta.total', 17)
            ->where('list.schema.filters.0.key', 'group')
            ->where('list.schema.filters.0.options.0.value', 'detergents')
            ->where('list.schema.filters.1.key', 'regulator')
            ->where('list.schema.filters.2.key', 'ifra_category')
            ->where('list.rows.0.code', 'dish_liquid')
            ->where('list.rows.0.regulator', 'EOS')
            ->where('list.rows.0.templates', 1));
    }

    public function test_search_finds_by_arabic_name_english_name_and_code(): void
    {
        foreach (['شامبو', 'Shampoo', 'shampoo'] as $term) {
            $this->get(route('families.index', ['search' => $term]))->assertInertia(fn (Assert $page) => $page
                ->where('list.meta.total', 1)
                ->where('list.rows.0.code', 'shampoo'));
        }
    }

    public function test_filters_by_regulator_group_and_ifra_category_narrow_the_list(): void
    {
        $this->get(route('families.index', ['regulator' => 'EDA']))->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 8));
        $this->get(route('families.index', ['group' => 'detergents']))->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 9));
        $this->get(route('families.index', ['ifra_category' => '10B']))->assertInertia(fn (Assert $page) => $page
            ->where('list.meta.total', 1)->where('list.rows.0.code', 'glass_cleaner'));
        $this->get(route('families.index', ['regulator' => 'EOS', 'search' => 'floor']))->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 1));
    }

    public function test_a_value_outside_the_whitelist_is_ignored_not_trusted(): void
    {
        $this->get(route('families.index', ['regulator' => 'FDA']))->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 17));
    }

    public function test_a_family_page_lists_calculations_with_their_calculator_and_what_the_lab_cannot_record_yet(): void
    {
        $this->get(route('families.show', 'bathroom_cleaner'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Families/Show')
            ->where('family.code', 'bathroom_cleaner')
            ->where('family.regulator', 'EOS')
            ->where('family.tax_regime', null)
            ->where('family.calculations.0.id', 'neutralization')
            ->where('family.calculations.0.href', route('calculators.show', 'neutralization', false))
            ->where('family.properties.0', ['id' => 'ph', 'lab_property' => 'ph'])
            ->where('family.properties.1', ['id' => 'corrosion', 'lab_property' => null])
            ->where('family.templates.0.verification_status', 'theoretical'));
    }

    public function test_a_planned_calculation_has_no_link(): void
    {
        $this->get(route('families.show', 'laundry_powder'))->assertInertia(fn (Assert $page) => $page
            ->where('family.calculations.1', ['id' => 'builders_fillers_moisture', 'status' => 'planned', 'href' => null]));
    }

    public function test_an_unknown_family_is_a_404(): void
    {
        $this->get('/families/nope')->assertNotFound();
    }

    public function test_the_template_list_filters_by_family_and_status_and_searches_both_languages(): void
    {
        $shampoo = ProductFamily::query()->where('code', 'shampoo')->value('id');

        $this->get(route('templates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Templates/Index')
            ->where('list.meta.total', 17)
            ->where('list.rows.0.status', 'theoretical'));
        $this->get(route('templates.index', ['family' => $shampoo]))->assertInertia(fn (Assert $page) => $page
            ->where('list.meta.total', 1)->where('list.rows.0.code', 'shampoo_basic'));
        $this->get(route('templates.index', ['status' => 'chemist_approved']))->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 0));
        $this->get(route('templates.index', ['status' => 'theoretical']))->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 17));
        $this->get(route('templates.index', ['search' => 'بلسم']))->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 1));
    }

    public function test_a_template_page_shows_roles_with_no_invented_share(): void
    {
        $this->get(route('templates.show', 'dish_liquid_basic'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Templates/Show')
            ->where('template.status', 'theoretical')
            ->where('template.family.regulator', 'EOS')
            ->where('template.lines.0', ['key' => 'water', 'role' => 'water', 'percent' => null, 'qs' => true, 'optional' => false])
            ->where('urls.use', route('templates.use', 'dish_liquid_basic', false)));

        $template = FormulaTemplate::query()->where('code', 'dish_liquid_basic')->sole();
        foreach ($template->lines as $line) {
            $this->assertNull($line['percentWW'], "{$line['key']} carries a share nobody provided");
        }
    }
}
