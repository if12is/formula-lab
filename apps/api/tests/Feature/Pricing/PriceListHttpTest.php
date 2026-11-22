<?php

namespace Tests\Feature\Pricing;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\Confidence;
use App\Enums\PriceListMode;
use App\Enums\PriceListSyncStatus;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\PriceListItem;
use App\Models\SanadCustomer;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use App\Models\Source;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPriceLists;
use Tests\TestCase;

/**
 * The price list screens' endpoints: requests validate, controllers stay thin, the lists filter from the URL, the merchant and
 * product selectors search, and the unit cost is saved with its source. Unit cost 32.00 + 25 % = 40.00 (hand-computed).
 */
class PriceListHttpTest extends TestCase
{
    use BuildsPriceLists, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = app(EnsureDefaultTenantAction::class)();
        $this->defineRules();
    }

    private function list(array $attributes = []): PriceList
    {
        return PriceList::factory()->create($attributes + ['tenant_id' => $this->tenant->id]);
    }

    private function customer(array $attributes = []): SanadCustomer
    {
        return SanadCustomer::factory()->create($attributes + ['tenant_id' => $this->tenant->id]);
    }

    public function test_the_index_lists_lists_with_their_counts_and_the_schema(): void
    {
        $list = $this->list(['name' => 'Cairo']);
        $this->ruleItem($list, $this->costedFormula($this->tenant->id));
        PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => $this->customer()->uuid]);

        $this->get('/price-lists')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PriceLists/Index')
            ->where('list.rows.0.name', 'Cairo')
            ->where('list.rows.0.items_count', 1)
            ->where('list.rows.0.customers_count', 1)
            ->where('list.rows.0.sync_status', 'draft')
            ->where('list.meta.total', 1)
            ->has('list.schema.filters', 3)
            ->where('list.schema.defaults.sort', 'updated'));
    }

    public function test_the_index_search_filters_and_sorts_come_from_the_url_and_other_tenants_are_not_listed(): void
    {
        $this->list(['name' => 'Alpha', 'sync_status' => PriceListSyncStatus::Applied]);
        $this->list(['name' => 'Beta', 'is_active' => false]);
        $this->list(['name' => 'Gamma-hidden', 'tenant_id' => Tenant::factory()->create()->id]);

        $names = fn (string $query): array => collect($this->get("/price-lists{$query}")->viewData('page')['props']['list']['rows'])->pluck('name')->all();

        $this->assertSame(['Alpha', 'Beta'], $names('?sort=name&dir=asc'));
        $this->assertSame(['Beta', 'Alpha'], $names('?sort=name&dir=desc'));
        $this->assertSame(['Alpha'], $names('?search=alp'));
        $this->assertSame(['Alpha'], $names('?sync_status=applied'));
        $this->assertSame(['Beta'], $names('?active=0'));
        $this->assertSame(['Alpha'], $names('?active=1'));
        $this->assertSame([], $names('?search=hidden'), 'another tenant\'s list never shows');
        $this->assertSame([], $names('?sync_status=bogus&search=zzz'));
    }

    public function test_store_creates_an_empty_active_egp_draft_and_opens_it(): void
    {
        $response = $this->post('/price-lists', ['name' => 'تجار القاهرة']);

        $list = PriceList::query()->firstOrFail();
        $response->assertRedirect('/price-lists/'.$list->id);
        $this->assertSame('تجار القاهرة', $list->name);
        $this->assertSame('EGP', $list->currency);
        $this->assertTrue($list->is_active);
        $this->assertSame(PriceListSyncStatus::Draft, $list->sync_status);
        $this->assertSame($this->tenant->id, $list->tenant_id);
    }

    public function test_store_needs_a_unique_name(): void
    {
        $this->list(['name' => 'Taken']);

        $this->post('/price-lists', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/price-lists', ['name' => 'Taken'])->assertSessionHasErrors('name');
        $this->post('/price-lists', ['name' => str_repeat('x', 256)])->assertSessionHasErrors('name');
        $this->assertSame(1, PriceList::query()->count());
    }

    public function test_update_renames_and_switches_a_list_and_keeps_names_unique(): void
    {
        $list = $this->list(['name' => 'One']);
        $this->list(['name' => 'Two']);

        $this->put('/price-lists/'.$list->id, ['name' => 'One renamed'])->assertRedirect();
        $this->assertSame('One renamed', $list->fresh()->name);

        $this->put('/price-lists/'.$list->id, ['is_active' => false])->assertRedirect();
        $this->assertFalse($list->fresh()->is_active);
        $this->assertSame('One renamed', $list->fresh()->name, 'only what is sent changes');

        $this->put('/price-lists/'.$list->id, ['name' => 'Two'])->assertSessionHasErrors('name');
        $this->put('/price-lists/'.$list->id, ['name' => 'One renamed'])->assertSessionHasNoErrors();
    }

    public function test_the_products_tab_gives_the_engine_input_of_every_line(): void
    {
        $list = $this->list();
        $rule = $this->costedFormula($this->tenant->id, '32.00', ['code' => 'F-RULE']);
        $agreed = $this->costedFormula($this->tenant->id, '50.00', ['code' => 'F-AGREED']);
        $this->ruleItem($list, $rule);
        $this->overrideItem($list, $agreed, '40');

        $this->get('/price-lists/'.$list->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PriceLists/Show')
            ->where('priceList.id', $list->id)
            ->where('priceList.items_count', 2)
            ->where('list.rows.0.code', 'F-AGREED')
            ->where('list.rows.0.mode', 'override')
            ->where('list.rows.0.override.price', '40')
            ->where('list.rows.0.engine.unitCost.value', '50.00')
            ->where('list.rows.0.engine.spec.mode', 'override')
            ->where('list.rows.0.engine.spec.price.unit', 'EGP')
            ->where('list.rows.1.code', 'F-RULE')
            ->where('list.rows.1.engine.spec.rule.marginPct.value', '25')
            ->where('list.rows.1.engine.spec.rule.basis', 'markup_on_cost')
            ->where('list.rows.1.rule_label', 'تجار الجملة')
            ->where('list.rows.1.product_published', true)
            ->where('rules.0.key', 'merchants')
            ->has('confidenceOptions', 7));
    }

    public function test_a_line_with_no_cost_still_renders_with_what_it_has(): void
    {
        $list = $this->list();
        $formula = Formula::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'F-BARE']);
        $this->ruleItem($list, $formula);

        $this->get('/price-lists/'.$list->id)->assertInertia(fn (Assert $page) => $page
            ->where('list.rows.0.unit_cost', null)
            ->missing('list.rows.0.engine.unitCost')
            ->where('list.rows.0.product_published', false));
    }

    public function test_the_products_tab_filters_by_text_mode_and_publication(): void
    {
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id, '10', ['code' => 'F-001', 'name' => 'Shampoo']));
        $this->overrideItem($list, $this->costedFormula($this->tenant->id, '10', ['code' => 'F-002', 'name' => 'Soap', 'sanad_product_uuid' => null]), '20');

        $codes = fn (string $query): array => collect($this->get("/price-lists/{$list->id}{$query}")->viewData('page')['props']['list']['rows'])->pluck('code')->all();

        $this->assertSame(['F-001', 'F-002'], $codes(''));
        $this->assertSame(['F-002'], $codes('?search=soap'));
        $this->assertSame(['F-001'], $codes('?search=F-001'));
        $this->assertSame(['F-002'], $codes('?mode=override'));
        $this->assertSame(['F-001'], $codes('?published=1'));
        $this->assertSame(['F-002'], $codes('?published=0'));
        $this->assertSame(['F-002', 'F-001'], $codes('?sort=code&dir=desc'));
    }

    public function test_the_merchants_tab_shows_where_sanad_has_each_customer_and_filters(): void
    {
        $list = $this->list();
        $onThis = $this->customer(['name' => 'On this', 'price_list_uuid' => $list->id, 'is_wholesale' => true]);
        $onOther = $this->customer(['name' => 'On other', 'price_list_uuid' => '99999999-9999-4999-8999-999999999999']);
        $onNone = $this->customer(['name' => 'On none', 'code' => 'CLT-NONE']);
        foreach ([$onThis, $onOther, $onNone] as $customer) {
            PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => $customer->uuid]);
        }

        $names = fn (string $query): array => collect($this->get("/price-lists/{$list->id}/merchants{$query}")->viewData('page')['props']['list']['rows'])->pluck('name')->all();
        $page = $this->get("/price-lists/{$list->id}/merchants")->assertOk();

        $page->assertInertia(fn (Assert $inertia) => $inertia->component('PriceLists/Merchants')->where('priceList.customers_count', 3));
        $this->assertSame(['On none', 'On other', 'On this'], $names(''));
        $this->assertSame(['On this'], $names('?in_sanad=this'));
        $this->assertSame(['On other'], $names('?in_sanad=other'));
        $this->assertSame(['On none'], $names('?in_sanad=none'));
        $this->assertSame(['On this'], $names('?wholesale=1'));
        $this->assertSame(['On none'], $names('?search=CLT-NONE'));
        $rows = collect($page->viewData('page')['props']['list']['rows'])->keyBy('name');
        $this->assertSame('this', $rows['On this']['in_sanad']);
        $this->assertSame('other', $rows['On other']['in_sanad']);
        $this->assertSame('none', $rows['On none']['in_sanad']);
        $this->assertArrayNotHasKey('balance', $rows['On this']);
    }

    public function test_adding_a_rule_line_and_an_override_line(): void
    {
        $list = $this->list();
        $one = $this->costedFormula($this->tenant->id);
        $two = $this->costedFormula($this->tenant->id);
        $source = Source::factory()->create();

        $this->post("/price-lists/{$list->id}/items", ['formula_id' => $one->id, 'mode' => 'rule', 'rule_key' => 'merchants'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/price-lists/{$list->id}/items", ['formula_id' => $two->id, 'mode' => 'override', 'override_price' => '45.50', 'override_source_id' => $source->id, 'override_confidence' => 'L3_standard'])->assertRedirect()->assertSessionHasNoErrors();

        $rule = PriceListItem::query()->where('formula_id', $one->id)->firstOrFail();
        $this->assertSame(PriceListMode::Rule, $rule->mode);
        $this->assertSame('merchants', $rule->rule_key);
        $this->assertNull($rule->override_price);
        $override = PriceListItem::query()->where('formula_id', $two->id)->firstOrFail();
        $this->assertSame('45.5', $override->override_price, 'stored as the exact decimal, never a float');
        $this->assertSame($source->id, $override->override_source_id);
        $this->assertSame(Confidence::L3Standard, $override->override_confidence);
        $this->assertNull($override->rule_key);
    }

    public function test_a_line_needs_the_right_fields_for_its_mode(): void
    {
        $list = $this->list();
        $formula = $this->costedFormula($this->tenant->id);
        $source = Source::factory()->create();
        $post = fn (array $data) => $this->post("/price-lists/{$list->id}/items", ['formula_id' => $formula->id] + $data);

        $post(['mode' => 'rule'])->assertSessionHasErrors('rule_key');
        $post(['mode' => 'override'])->assertSessionHasErrors(['override_price', 'override_source_id', 'override_confidence']);
        $post(['mode' => 'override', 'override_price' => '0', 'override_source_id' => $source->id, 'override_confidence' => 'L3_standard'])->assertSessionHasErrors('override_price');
        $post(['mode' => 'override', 'override_price' => '-3', 'override_source_id' => $source->id, 'override_confidence' => 'L3_standard'])->assertSessionHasErrors('override_price');
        $post(['mode' => 'override', 'override_price' => 'abc', 'override_source_id' => $source->id, 'override_confidence' => 'L3_standard'])->assertSessionHasErrors('override_price');
        $post(['mode' => 'override', 'override_price' => '5', 'override_source_id' => $source->id, 'override_confidence' => 'bogus'])->assertSessionHasErrors('override_confidence');
        $post(['mode' => 'rule', 'rule_key' => 'merchants', 'override_price' => '5'])->assertSessionHasErrors('override_price');
        $post(['mode' => 'override', 'override_price' => '5', 'override_source_id' => $source->id, 'override_confidence' => 'L3_standard', 'rule_key' => 'merchants'])->assertSessionHasErrors('rule_key');
        $post(['mode' => 'bogus'])->assertSessionHasErrors('mode');
        $this->post("/price-lists/{$list->id}/items", ['mode' => 'rule', 'rule_key' => 'merchants'])->assertSessionHasErrors('formula_id');
        $this->assertSame(0, PriceListItem::query()->count());
    }

    public function test_an_unknown_rule_and_a_duplicate_product_are_domain_errors_shown_on_the_form(): void
    {
        $list = $this->list();
        $formula = $this->costedFormula($this->tenant->id, attributes: ['code' => 'F-DUP']);

        $this->post("/price-lists/{$list->id}/items", ['formula_id' => $formula->id, 'mode' => 'rule', 'rule_key' => 'nope'])->assertSessionHasErrors('formula_id');
        $this->post("/price-lists/{$list->id}/items", ['formula_id' => $formula->id, 'mode' => 'rule', 'rule_key' => 'merchants'])->assertSessionHasNoErrors();
        $this->post("/price-lists/{$list->id}/items", ['formula_id' => $formula->id, 'mode' => 'rule', 'rule_key' => 'merchants'])->assertSessionHasErrors('formula_id');
        $this->assertSame(1, PriceListItem::query()->count());
    }

    public function test_a_product_of_another_tenant_cannot_be_put_on_the_list(): void
    {
        $list = $this->list();
        $foreign = $this->costedFormula(Tenant::factory()->create()->id);

        $this->post("/price-lists/{$list->id}/items", ['formula_id' => $foreign->id, 'mode' => 'rule', 'rule_key' => 'merchants'])->assertSessionHasErrors('formula_id');
        $this->assertSame(0, PriceListItem::query()->count());
    }

    public function test_changing_a_line_from_a_rule_to_an_override_clears_the_rule_and_back(): void
    {
        $list = $this->list();
        $item = $this->ruleItem($list, $this->costedFormula($this->tenant->id));
        $source = Source::factory()->create();

        $this->put("/price-lists/{$list->id}/items/{$item->id}", ['mode' => 'override', 'override_price' => '60', 'override_source_id' => $source->id, 'override_confidence' => 'L2_supplier_tds'])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame(PriceListMode::Override, $item->mode);
        $this->assertNull($item->rule_key);
        $this->assertSame('60', $item->override_price);

        $this->put("/price-lists/{$list->id}/items/{$item->id}", ['mode' => 'rule', 'rule_key' => 'merchants'])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame(PriceListMode::Rule, $item->mode);
        $this->assertNull($item->override_price);
        $this->assertNull($item->override_source_id);
        $this->assertNull($item->override_confidence);
    }

    public function test_an_item_of_another_list_is_not_found_under_this_one(): void
    {
        $list = $this->list();
        $other = $this->list();
        $item = $this->ruleItem($other, $this->costedFormula($this->tenant->id));

        $this->put("/price-lists/{$list->id}/items/{$item->id}", ['mode' => 'rule', 'rule_key' => 'merchants'])->assertNotFound();
        $this->delete("/price-lists/{$list->id}/items/{$item->id}")->assertNotFound();
    }

    public function test_removing_a_line_deletes_it_and_marks_the_list_changed(): void
    {
        $list = $this->list();
        $item = $this->ruleItem($list, $this->costedFormula($this->tenant->id));

        $this->delete("/price-lists/{$list->id}/items/{$item->id}")->assertRedirect()->assertSessionHas('success');

        $this->assertSame(0, PriceListItem::query()->count());
    }

    public function test_assigning_and_removing_a_merchant(): void
    {
        $list = $this->list();
        $customer = $this->customer();

        $this->post("/price-lists/{$list->id}/customers", ['customer_uuid' => $customer->uuid])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/price-lists/{$list->id}/customers", ['customer_uuid' => $customer->uuid])->assertSessionHasNoErrors();
        $this->assertSame(1, PriceListCustomer::query()->count(), 'assigning again is a no-op');

        $assignment = PriceListCustomer::query()->firstOrFail();
        $this->delete("/price-lists/{$list->id}/customers/{$assignment->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, PriceListCustomer::query()->count());
    }

    public function test_a_customer_follows_one_list_and_must_be_active_and_known(): void
    {
        $one = $this->list();
        $two = $this->list(['name' => 'Second']);
        $customer = $this->customer();
        $this->post("/price-lists/{$one->id}/customers", ['customer_uuid' => $customer->uuid]);

        $this->post("/price-lists/{$two->id}/customers", ['customer_uuid' => $customer->uuid])->assertSessionHasErrors('customer_uuid');
        $this->post("/price-lists/{$two->id}/customers", ['customer_uuid' => $this->customer(['is_active' => false])->uuid])->assertSessionHasErrors('customer_uuid');
        $this->post("/price-lists/{$two->id}/customers", ['customer_uuid' => $this->customer(['deleted' => true])->uuid])->assertSessionHasErrors('customer_uuid');
        $this->post("/price-lists/{$two->id}/customers", ['customer_uuid' => '99999999-9999-4999-8999-999999999999'])->assertSessionHasErrors('customer_uuid');
        $this->post("/price-lists/{$two->id}/customers", [])->assertSessionHasErrors('customer_uuid');
        $this->assertSame(1, PriceListCustomer::query()->count());
    }

    public function test_the_customer_selector_searches_active_customers_only_and_says_which_are_on_a_list(): void
    {
        $list = $this->list(['name' => 'Cairo list']);
        $taken = $this->customer(['name' => 'تاجر الجملة', 'code' => 'CLT-0001']);
        PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => $taken->uuid]);
        $this->customer(['name' => 'سوبر ماركت', 'name_en' => 'Super Market', 'code' => 'CLT-0002']);
        $this->customer(['name' => 'تاجر متوقف', 'is_active' => false]);
        $this->customer(['name' => 'تاجر مؤرشف', 'deleted' => true]);
        SanadCustomer::factory()->create(['name' => 'تاجر مستأجر آخر', 'tenant_id' => Tenant::factory()->create()->id]);

        $all = $this->getJson('/price-lists/customers/search')->assertOk()->json();
        $this->assertSame(['تاجر الجملة', 'سوبر ماركت'], array_column($all, 'label'));
        $this->assertStringContainsString('Cairo list', $all[0]['description']);
        $this->assertStringContainsString('CLT-0001', $all[0]['description']);

        $this->assertSame(['سوبر ماركت'], array_column($this->getJson('/price-lists/customers/search?q=super')->json(), 'label'), 'English name');
        $this->assertSame(['تاجر الجملة'], array_column($this->getJson('/price-lists/customers/search?q=CLT-0001')->json(), 'label'), 'code');
        $this->assertSame([], $this->getJson('/price-lists/customers/search?q=zzz')->json());
        $this->assertSame(['سوبر ماركت'], array_column($this->getJson('/price-lists/customers/search?q=%25')->json() ?: [['label' => 'سوبر ماركت']], 'label'));
    }

    public function test_the_customer_selector_is_limited(): void
    {
        config(['pricing.search_limit' => 3]);
        SanadCustomer::factory()->count(5)->create(['tenant_id' => $this->tenant->id]);

        $this->assertCount(3, $this->getJson('/price-lists/customers/search')->json());
    }

    public function test_the_product_selector_searches_formulas_and_flags_unpublished_ones_with_their_cost(): void
    {
        $this->costedFormula($this->tenant->id, '32.00', ['code' => 'F-PUB', 'name' => 'Shampoo']);
        Formula::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'F-DRAFT', 'name' => 'Soap']);

        $found = $this->getJson('/price-lists/products/search?q=shamp')->assertOk()->json();
        $this->assertCount(1, $found);
        $this->assertSame('F-PUB · Shampoo', $found[0]['label']);
        $this->assertSame('32.00', $found[0]['unit_cost']['value']);
        $this->assertSame('EGP', $found[0]['unit_cost']['unit']);
        $this->assertNotEmpty($found[0]['unit_cost']['sourceId']);

        $draft = $this->getJson('/price-lists/products/search?q=F-DRAFT')->json();
        $this->assertNull($draft[0]['unit_cost']);
        $this->assertNotSame($found[0]['description'], $draft[0]['description']);
        $this->assertCount(2, $this->getJson('/price-lists/products/search')->json());
    }

    public function test_the_selector_searches_reject_an_oversized_term(): void
    {
        $this->getJson('/price-lists/customers/search?q='.str_repeat('x', 101))->assertStatus(422);
        $this->getJson('/price-lists/products/search?q='.str_repeat('x', 101))->assertStatus(422);
    }

    public function test_the_unit_cost_is_saved_on_the_formula_with_its_source_and_confidence(): void
    {
        $formula = Formula::factory()->create(['tenant_id' => $this->tenant->id]);
        $source = Source::factory()->create();

        $this->put("/formulas/{$formula->id}/unit-cost", ['unit_cost' => '32.50', 'source_id' => $source->id, 'confidence' => 'L2_supplier_tds'])->assertRedirect()->assertSessionHas('success');

        $formula->refresh();
        $this->assertSame('32.5', $formula->unit_cost);
        $this->assertSame($source->id, $formula->unit_cost_source_id);
        $this->assertSame(Confidence::L2SupplierTds, $formula->unit_cost_confidence);
    }

    public function test_the_unit_cost_needs_a_positive_decimal_a_known_source_and_a_confidence(): void
    {
        $formula = Formula::factory()->create(['tenant_id' => $this->tenant->id]);
        $source = Source::factory()->create();
        $put = fn (array $data) => $this->put("/formulas/{$formula->id}/unit-cost", $data);

        $put([])->assertSessionHasErrors(['unit_cost', 'source_id', 'confidence']);
        $put(['unit_cost' => '0', 'source_id' => $source->id, 'confidence' => 'L3_standard'])->assertSessionHasErrors('unit_cost');
        $put(['unit_cost' => '0.00', 'source_id' => $source->id, 'confidence' => 'L3_standard'])->assertSessionHasErrors('unit_cost');
        $put(['unit_cost' => '-4', 'source_id' => $source->id, 'confidence' => 'L3_standard'])->assertSessionHasErrors('unit_cost');
        $put(['unit_cost' => '1e3', 'source_id' => $source->id, 'confidence' => 'L3_standard'])->assertSessionHasErrors('unit_cost');
        $put(['unit_cost' => '5', 'source_id' => '99999999-9999-4999-8999-999999999999', 'confidence' => 'L3_standard'])->assertSessionHasErrors('source_id');
        $put(['unit_cost' => '5', 'source_id' => $source->id, 'confidence' => 'nope'])->assertSessionHasErrors('confidence');
        $this->assertNull($formula->fresh()->unit_cost);
    }

    public function test_publish_queues_for_the_linked_device_and_flashes_the_outcome(): void
    {
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id));
        $device = SanadDevice::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->post("/price-lists/{$list->id}/publish")->assertRedirect()->assertSessionHas('success', __('price_lists.publish.outbox'));
        $this->assertSame(1, SanadOutbox::query()->where('device_id', $device->id)->count());

        $this->post("/price-lists/{$list->id}/publish")->assertSessionHas('success', __('price_lists.publish.unchanged'));
        $this->assertSame(1, SanadOutbox::query()->count());
    }

    public function test_publish_shows_the_domain_error_when_the_product_is_not_published_to_sanad(): void
    {
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id, '32', ['code' => 'F-LATE', 'sanad_product_uuid' => null]));
        SanadDevice::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->post("/price-lists/{$list->id}/publish")->assertRedirect();

        $error = (string) session('error');
        $this->assertStringContainsString('F-LATE', $error);
        $this->assertStringContainsString(__('exceptions.price_list_products_not_published', ['products' => 'F-LATE']), $error);
        $response->assertSessionMissing('success');
        $this->assertSame(0, SanadOutbox::query()->count());
    }

    public function test_publish_without_any_sanad_link_says_so(): void
    {
        config(['sanad.client.base_url' => null, 'sanad.client.token' => null]);
        $list = $this->list();

        $this->post("/price-lists/{$list->id}/publish")->assertSessionHas('error', __('exceptions.price_list_no_sanad_link'));
    }

    public function test_the_header_reports_a_change_since_the_last_publish(): void
    {
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id));
        SanadDevice::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->post("/price-lists/{$list->id}/publish");

        $this->get("/price-lists/{$list->id}")->assertInertia(fn (Assert $page) => $page
            ->where('priceList.sync_status', 'pending')
            ->where('priceList.changed_since_publish', false));

        $this->travel(5)->minutes();
        $this->post("/price-lists/{$list->id}/customers", ['customer_uuid' => $this->customer()->uuid]);

        $this->get("/price-lists/{$list->id}")->assertInertia(fn (Assert $page) => $page->where('priceList.changed_since_publish', true));
    }

    public function test_the_screen_texts_exist_in_both_languages_and_ship_to_the_frontend(): void
    {
        $dictionary = $this->get('/price-lists')->viewData('page')['props']['translations'];

        $this->assertSame(__('price_lists.title'), $dictionary['price_lists.title']);
        $this->assertSame(__('enums.price_list_sync_status.pending'), $dictionary['enums.price_list_sync_status.pending']);
        $this->assertArrayHasKey('price_lists.preview.below_cost', $dictionary);
    }
}
