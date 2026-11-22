<?php

namespace Tests\Feature\Pricing;

use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\PriceListItem;
use App\Models\SanadCustomer;
use App\Models\Source;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPriceLists;
use Tests\TestCase;

/** What the database itself refuses, so a price list can never hold a price without a source or a second list for a customer. */
class PriceListSchemaTest extends TestCase
{
    use BuildsPriceLists, RefreshDatabase;

    private function insertItem(array $attributes): void
    {
        $list = PriceList::factory()->create();
        DB::table('price_list_items')->insert($attributes + [
            'id' => (string) Str::uuid(), 'price_list_id' => $list->id, 'formula_id' => Formula::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_rule_line_cannot_carry_an_override_and_an_override_needs_price_source_and_confidence(): void
    {
        $source = Source::factory()->create();
        $bad = [
            ['mode' => 'rule', 'rule_key' => null],
            ['mode' => 'rule', 'rule_key' => 'm', 'override_price' => '5'],
            ['mode' => 'override', 'override_price' => null, 'override_source_id' => $source->id, 'override_confidence' => 'L3_standard'],
            ['mode' => 'override', 'override_price' => '5', 'override_source_id' => null, 'override_confidence' => 'L3_standard'],
            ['mode' => 'override', 'override_price' => '5', 'override_source_id' => $source->id, 'override_confidence' => null],
            ['mode' => 'override', 'override_price' => '0', 'override_source_id' => $source->id, 'override_confidence' => 'L3_standard'],
            ['mode' => 'override', 'override_price' => '5', 'override_source_id' => $source->id, 'override_confidence' => 'L3_standard', 'rule_key' => 'm'],
            ['mode' => 'other', 'rule_key' => 'm'],
        ];

        foreach ($bad as $attributes) {
            $this->refuses(fn () => $this->insertItem($attributes));
        }
    }

    public function test_a_formula_is_on_a_list_once_and_a_customer_is_on_one_list(): void
    {
        $list = PriceList::factory()->create();
        $formula = Formula::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'formula_id' => $formula->id]);
        $this->refuses(fn () => PriceListItem::factory()->create(['price_list_id' => $list->id, 'formula_id' => $formula->id]));

        $customer = SanadCustomer::factory()->create();
        PriceListCustomer::factory()->create(['customer_uuid' => $customer->uuid]);
        $this->refuses(fn () => PriceListCustomer::factory()->create(['customer_uuid' => $customer->uuid]));
    }

    public function test_a_list_is_egp_only_and_its_name_is_unique_inside_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        PriceList::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Same']);

        $this->refuses(fn () => PriceList::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Same']));
        PriceList::factory()->create(['name' => 'Same']);
        $this->refuses(fn () => PriceList::factory()->create(['currency' => 'USD']));
        $this->refuses(fn () => DB::table('price_lists')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'X', 'sync_status' => 'bogus', 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_the_unit_cost_is_all_or_nothing_and_positive(): void
    {
        $source = Source::factory()->create();

        $this->refuses(fn () => Formula::factory()->create(['unit_cost' => '5']));
        $this->refuses(fn () => Formula::factory()->create(['unit_cost' => '5', 'unit_cost_source_id' => $source->id]));
        $this->refuses(fn () => Formula::factory()->create(['unit_cost' => '0', 'unit_cost_source_id' => $source->id, 'unit_cost_confidence' => 'L3_standard']));
        $this->refuses(fn () => Formula::factory()->create(['unit_cost_source_id' => $source->id]));
        $this->assertNotNull(Formula::factory()->create(['unit_cost' => '5', 'unit_cost_source_id' => $source->id, 'unit_cost_confidence' => 'L3_standard'])->id);
    }

    private function refuses(callable $call): void
    {
        try {
            // A savepoint: PostgreSQL aborts the whole test transaction on a refused row otherwise.
            DB::transaction($call);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The database accepted a row it must refuse.');
    }
}
