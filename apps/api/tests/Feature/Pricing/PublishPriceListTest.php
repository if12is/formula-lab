<?php

namespace Tests\Feature\Pricing;

use App\Actions\Pricing\BuildPriceListPayloadAction;
use App\Actions\Pricing\PublishPriceListToSanadAction;
use App\Enums\PriceListSyncStatus;
use App\Enums\Sanad\SanadOutboxKind;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Exceptions\Domain\PriceListException;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\SanadCustomer;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPriceLists;
use Tests\fakes\sanad\SanadFake;
use Tests\Feature\Sanad\SanadTestCase;

/**
 * Publishing a price list (contract 8.6): the `price_list_upsert` outbox change carries a full snapshot, every price is the
 * engine's two-place string, a republish updates the same list uuid and is idempotent, and nothing is built for a list that is
 * not ready. Hand-computed: unit cost 32.00 + 25 % = 40.00; an agreed price of 45.5 is sent as "45.50".
 */
class PublishPriceListTest extends SanadTestCase
{
    use BuildsPriceLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defineRules();
    }

    private function list(array $attributes = []): PriceList
    {
        return PriceList::factory()->create($attributes + ['tenant_id' => $this->tenant->id, 'name' => 'Cairo merchants']);
    }

    private function publish(PriceList $list): ?SanadOutbox
    {
        $result = app(PublishPriceListToSanadAction::class)($list->fresh(), $this->device);

        return $result->outboxId === null ? null : SanadOutbox::query()->findOrFail($result->outboxId);
    }

    public function test_it_queues_a_price_list_upsert_with_engine_prices_as_decimal_strings(): void
    {
        $list = $this->list();
        $rule = $this->costedFormula($this->tenant->id, '32.00');
        $agreed = $this->costedFormula($this->tenant->id, '30.00');
        $this->ruleItem($list, $rule);
        $this->overrideItem($list, $agreed, '45.5');
        $customer = SanadCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => $customer->uuid]);

        $row = $this->publish($list);

        $this->assertSame(SanadOutboxKind::PriceListUpsert, $row->kind);
        $this->assertSame($list->id, $row->external_id);
        $payload = $row->payload;
        $this->assertSame($list->id, $payload['uuid']);
        $this->assertSame('Cairo merchants', $payload['name']);
        $this->assertSame('EGP', $payload['currency']);
        $this->assertTrue($payload['is_active']);
        $prices = collect($payload['items'])->pluck('price', 'product_uuid');
        $this->assertSame('40.00', $prices[$rule->sanad_product_uuid], '32.00 + 25 % = 40.00');
        $this->assertSame('45.50', $prices[$agreed->sanad_product_uuid]);
        $this->assertSame([$customer->uuid], $payload['assign_customers']);
        $this->assertSame([], $payload['unassign_customers']);
        foreach ($payload['items'] as $item) {
            $this->assertIsString($item['price'], 'a price is a decimal string, never a JSON number');
        }

        $list->refresh();
        $this->assertSame(PriceListSyncStatus::Pending, $list->sync_status);
        $this->assertSame($row->id, $list->sync_outbox_id);
        $this->assertNotNull($list->published_at);
    }

    public function test_a_distribution_cost_of_the_rule_is_added_after_the_margin(): void
    {
        $this->defineRules(['distribution_cost' => '10']);
        $list = $this->list();
        $formula = $this->costedFormula($this->tenant->id, '32.00');
        $this->ruleItem($list, $formula);

        $payload = $this->publish($list)->payload;

        $this->assertSame('50.00', $payload['items'][0]['price'], '32 × 1.25 = 40, + 10 = 50');
    }

    public function test_publishing_twice_without_a_change_queues_once(): void
    {
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id));

        $first = $this->publish($list);
        $second = app(PublishPriceListToSanadAction::class)($list->fresh(), $this->device);

        $this->assertTrue($second->unchanged);
        $this->assertSame($first->id, $second->outboxId);
        $this->assertSame(1, SanadOutbox::query()->where('kind', SanadOutboxKind::PriceListUpsert)->count());
    }

    public function test_a_republish_after_a_change_sends_the_same_list_uuid_and_the_new_price(): void
    {
        $list = $this->list();
        $formula = $this->costedFormula($this->tenant->id, '32.00');
        $this->ruleItem($list, $formula);
        $first = $this->publish($list);

        $formula->update(['unit_cost' => '36.00']);
        $second = $this->publish($list);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->payload['uuid'], $second->payload['uuid']);
        $this->assertSame('45.00', $second->payload['items'][0]['price'], '36 × 1.25 = 45');
        $this->assertSame(2, SanadOutbox::query()->where('external_id', $list->id)->count());
    }

    public function test_a_product_taken_off_the_list_goes_out_with_a_null_price_and_a_merchant_with_unassign(): void
    {
        $list = $this->list();
        $keep = $this->costedFormula($this->tenant->id, '32.00');
        $drop = $this->costedFormula($this->tenant->id, '40.00');
        $this->ruleItem($list, $keep);
        $dropItem = $this->ruleItem($list, $drop);
        $stay = SanadCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        $leave = SanadCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => $stay->uuid]);
        $leaving = PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => $leave->uuid]);
        $this->publish($list);

        $dropItem->delete();
        $leaving->delete();
        $payload = $this->publish($list)->payload;

        $byProduct = collect($payload['items'])->keyBy('product_uuid');
        $this->assertSame('40.00', $byProduct[$keep->sanad_product_uuid]['price']);
        $this->assertNull($byProduct[$drop->sanad_product_uuid]['price'], 'null removes the product from the list in Sanad');
        $this->assertSame([$stay->uuid], $payload['assign_customers']);
        $this->assertSame([$leave->uuid], $payload['unassign_customers']);
    }

    public function test_an_inactive_list_sends_no_assignments_because_sanad_refuses_customers_on_it(): void
    {
        $list = $this->list(['is_active' => false]);
        $this->ruleItem($list, $this->costedFormula($this->tenant->id));
        $customer = SanadCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => $customer->uuid]);

        $payload = $this->publish($list)->payload;

        $this->assertFalse($payload['is_active']);
        $this->assertSame([], $payload['assign_customers']);
    }

    public function test_a_product_not_published_to_sanad_stops_the_publish_with_its_code(): void
    {
        $list = $this->list();
        $unpublished = $this->costedFormula($this->tenant->id, '32.00', ['code' => 'F-NOT-PUB', 'sanad_product_uuid' => null]);
        $this->ruleItem($list, $unpublished);

        $this->assertThrowsException(PriceListException::class, fn () => $this->publish($list), 'F-NOT-PUB');
        $this->assertSame(0, SanadOutbox::query()->count());
        $this->assertSame(PriceListSyncStatus::Draft, $list->fresh()->sync_status);
    }

    public function test_a_line_the_engine_cannot_price_stops_the_publish_and_names_the_product(): void
    {
        $list = $this->list();
        $noCost = Formula::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'F-NO-COST', 'sanad_product_uuid' => (string) Str::uuid()]);
        $this->ruleItem($list, $noCost);

        $this->assertThrowsException(PriceListException::class, fn () => $this->publish($list), 'F-NO-COST');
        $this->assertSame(0, SanadOutbox::query()->count());
    }

    public function test_a_rule_missing_from_the_settings_is_missing_never_a_default_margin(): void
    {
        config(['pricing.rules' => []]);
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id, '32.00', ['code' => 'F-RULELESS']));

        $this->assertThrowsException(PriceListException::class, fn () => $this->publish($list), 'F-RULELESS');
    }

    public function test_a_rule_without_a_source_is_incomplete_and_blocks_the_publish(): void
    {
        $this->defineRules(['source' => null]);
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id, '32.00', ['code' => 'F-NOSRC']));

        $this->assertThrowsException(PriceListException::class, fn () => $this->publish($list), 'F-NOSRC');
    }

    public function test_a_price_below_cost_is_published_it_is_a_warning_not_a_refusal(): void
    {
        $list = $this->list();
        $formula = $this->costedFormula($this->tenant->id, '50.00');
        $this->overrideItem($list, $formula, '40');

        $payload = $this->publish($list)->payload;

        $this->assertSame('40.00', $payload['items'][0]['price']);
    }

    public function test_more_items_than_sanad_accepts_in_one_request_are_refused(): void
    {
        config(['sanad.price_lists.max_items_per_request' => 2]);
        $list = $this->list();
        foreach (range(1, 3) as $ignored) {
            $this->ruleItem($list, $this->costedFormula($this->tenant->id));
        }

        $this->assertThrowsException(PriceListException::class, fn () => $this->publish($list));
    }

    public function test_the_payload_hash_is_stable_for_the_same_content(): void
    {
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id));

        $a = app(BuildPriceListPayloadAction::class)($list)->hash();
        $b = app(BuildPriceListPayloadAction::class)($list->fresh())->hash();

        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    public function test_a_device_of_another_tenant_cannot_publish_the_list(): void
    {
        $list = $this->list();
        $other = SanadDevice::factory()->create();

        $this->assertThrowsException(PriceListException::class, fn () => app(PublishPriceListToSanadAction::class)($list, $other));
    }

    public function test_the_outbox_serves_the_price_list_to_sanad_with_produced_at(): void
    {
        $list = $this->list();
        $this->ruleItem($list, $this->costedFormula($this->tenant->id));
        $row = $this->publish($list);

        $response = $this->getJson('/api/sanad/v1/outbox', $this->headers())->assertOk();

        $served = $response->json('data.0');
        $this->assertSame($row->id, $served['id']);
        $this->assertSame('price_list_upsert', $served['type']);
        $this->assertSame($list->id, $served['external_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $served['produced_at']);
    }

    public function test_without_a_device_the_list_goes_over_lan_in_contract_order(): void
    {
        Sleep::fake();
        SanadFake::use();
        SanadFake::upsertPriceList();
        SanadFake::assignCustomerPriceList();
        $list = $this->list();
        $formula = $this->costedFormula($this->tenant->id, '32.00');
        $this->ruleItem($list, $formula);
        $customer = SanadCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
        PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => $customer->uuid]);

        $result = app(PublishPriceListToSanadAction::class)($list, null);

        $this->assertSame('lan', $result->mode);
        $this->assertSame(PriceListSyncStatus::Applied, $list->fresh()->sync_status);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/price-lists/'.$list->id)
            && $request['items'] === [['product_uuid' => $formula->sanad_product_uuid, 'price' => '40.00']]
            && $request->hasHeader('Idempotency-Key'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/customers/'.$customer->uuid.'/price-list') && $request['price_list_uuid'] === $list->id);
    }

    public function test_without_a_device_and_without_a_lan_address_there_is_a_clear_error(): void
    {
        config(['sanad.client.base_url' => null, 'sanad.client.token' => null]);
        $list = $this->list();

        $this->assertThrowsException(PriceListException::class, fn () => app(PublishPriceListToSanadAction::class)($list, null));
    }

    public function test_the_outbox_status_enum_is_what_the_list_status_follows(): void
    {
        $this->assertSame(['pending', 'applied', 'failed', 'conflict'], SanadOutboxStatus::values());
    }
}
