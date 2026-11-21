<?php

namespace Tests\Feature\Sanad;

use App\Services\Dev\MockSanadService;
use Tests\fakes\sanad\SanadContract;
use Tests\TestCase;

class MockSanadRoutesTest extends TestCase
{
    private const BASE = '/__mock/sanad/api/v1/manufacturing';

    private const GLYCERIN = '11111111-1111-4111-8111-111111111111';

    private const BOTTLE = '33333333-3333-4333-8333-333333333333';

    private const FG = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        app(MockSanadService::class)->reset();
    }

    /** @return array<string, string> */
    private function auth(?string $key = null, ?string $token = null): array
    {
        return array_filter([
            'Authorization' => 'Bearer '.($token ?? config('sanad.mock.token')),
            'Idempotency-Key' => $key,
            'Accept' => 'application/json',
        ]);
    }

    public function test_the_mock_requires_a_bearer_token_and_a_write_ability_for_writes(): void
    {
        $this->getJson(self::BASE.'/products', ['Accept' => 'application/json'])->assertStatus(401);
        $this->getJson(self::BASE.'/products', $this->auth(token: 'nope'))->assertStatus(401);

        $readOnly = config('sanad.mock.read_only_token');
        $this->getJson(self::BASE.'/products', $this->auth(token: $readOnly))->assertOk();
        $this->putJson(self::BASE.'/products/'.self::FG, [], $this->auth('k', $readOnly))->assertStatus(403);
    }

    public function test_writes_need_an_idempotency_key_and_replay_it(): void
    {
        $body = ['name' => 'X', 'item_kind' => 'raw_material', 'uom' => 'kg'];
        $uuid = '70000000-0000-4000-8000-000000000001';

        $this->putJson(self::BASE.'/products/'.$uuid, $body, $this->auth())->assertStatus(400);
        $this->putJson(self::BASE.'/products/'.$uuid, $body, $this->auth('k1'))->assertStatus(201);
        $this->putJson(self::BASE.'/products/'.$uuid, $body, $this->auth('k1'))->assertStatus(201)->assertHeader('Idempotent-Replay', 'true');
        $this->putJson(self::BASE.'/products/'.$uuid, ['name' => 'Y'] + $body, $this->auth('k1'))->assertStatus(422);
    }

    public function test_products_return_contract_shaped_rows_and_tombstones(): void
    {
        $page = $this->getJson(self::BASE.'/products', $this->auth())->assertOk()->json();

        SanadContract::assertPage($page);
        $this->assertCount(6, $page['data']);
        $this->assertNull($page['next_cursor']);
        foreach ($page['data'] as $row) {
            ($row['deleted'] ?? false) ? SanadContract::assertTombstone($row) : SanadContract::assertProduct($row);
        }
        $this->assertSame('41.20', collect($page['data'])->firstWhere('uuid', self::GLYCERIN)['cost_price']);
    }

    public function test_kind_filter_updated_since_and_cursor_paging(): void
    {
        $raw = $this->getJson(self::BASE.'/products?kind=raw_material', $this->auth())->json('data');
        $this->assertCount(3, $raw);

        $since = $this->getJson(self::BASE.'/products?updated_since=2026-11-20T11:00:00Z', $this->auth())->json('data');
        $this->assertSame([self::FG, '55555555-5555-4555-8555-555555555555', '66666666-6666-4666-8666-666666666666'], array_column($since, 'uuid'));

        config(['sanad.mock.page_size' => 2]);
        $first = $this->getJson(self::BASE.'/products', $this->auth())->json();
        $this->assertCount(2, $first['data']);
        $this->assertNotNull($first['next_cursor']);
        $second = $this->getJson(self::BASE.'/products?cursor='.$first['next_cursor'], $this->auth())->json();
        $third = $this->getJson(self::BASE.'/products?cursor='.$second['next_cursor'], $this->auth())->json();
        $this->assertNull($third['next_cursor']);
        $uuids = array_merge(array_column($first['data'], 'uuid'), array_column($second['data'], 'uuid'), array_column($third['data'], 'uuid'));
        $this->assertCount(6, array_unique($uuids));
        $this->getJson(self::BASE.'/products?kind=bogus', $this->auth())->assertStatus(422);
    }

    public function test_suppliers_purchases_and_stock_match_the_contract(): void
    {
        $suppliers = $this->getJson(self::BASE.'/suppliers', $this->auth())->assertOk()->json();
        SanadContract::assertPage($suppliers);
        SanadContract::assertSupplier($suppliers['data'][0]);
        SanadContract::assertTombstone($suppliers['data'][2]);

        $purchases = $this->getJson(self::BASE.'/purchases', $this->auth())->assertOk()->json();
        SanadContract::assertPage($purchases);
        SanadContract::assertPurchase($purchases['data'][0]);

        $stock = $this->getJson(self::BASE.'/stock', $this->auth())->assertOk()->json();
        SanadContract::assertPage($stock);
        foreach ($stock['data'] as $row) {
            SanadContract::assertStock($row);
        }
    }

    public function test_an_upserted_product_shows_up_in_the_feed_and_keeps_unrounded_input_rounded_to_two_places(): void
    {
        $uuid = '70000000-0000-4000-8000-000000000002';

        $this->putJson(self::BASE.'/products/'.$uuid, [
            'name' => 'Premium', 'item_kind' => 'finished_good', 'is_sellable' => true, 'uom' => 'unit', 'cost_price' => '18.4573', 'sell_price' => '39.00',
        ], $this->auth('u1'))->assertStatus(201)->assertJson(['uuid' => $uuid, 'item_kind' => 'finished_good', 'is_sellable' => true]);

        $row = collect($this->getJson(self::BASE.'/products', $this->auth())->json('data'))->firstWhere('uuid', $uuid);
        SanadContract::assertProduct($row);
        $this->assertSame('18.46', $row['cost_price']);

        $this->putJson(self::BASE.'/products/'.$uuid, ['name' => 'Premium 2', 'item_kind' => 'finished_good'], $this->auth('u2'))->assertStatus(200);
        $this->putJson(self::BASE.'/products/'.$uuid, ['item_kind' => 'nope'], $this->auth('u3'))->assertStatus(422);
    }

    public function test_wholesale_prices_update_only_the_given_fields_and_404_for_unknown_products(): void
    {
        $this->putJson(self::BASE.'/products/'.self::FG.'/wholesale-prices', ['wholesale_price' => '29.5', 'tier' => 'distributors'], $this->auth('w1'))
            ->assertOk()->assertJson(['uuid' => self::FG, 'wholesale_price' => '29.50', 'guide_price' => '45.00', 'sell_price' => '39.00']);
        $this->putJson(self::BASE.'/products/90000000-0000-4000-8000-000000000009/wholesale-prices', ['sell_price' => '1'], $this->auth('w2'))->assertStatus(404);
    }

    public function test_consume_is_all_or_nothing_with_shortfalls_and_exact_costs(): void
    {
        $lines = [['product_uuid' => self::GLYCERIN, 'quantity' => '62.500000', 'uom' => 'kg'], ['product_uuid' => self::BOTTLE, 'quantity' => '505.000000', 'uom' => 'unit']];

        $ok = $this->postJson(self::BASE.'/production-orders/po-1/consume', ['lines' => $lines, 'consumed_on' => '2026-11-25'], $this->auth('c1'))->assertOk();
        $ok->assertJson(['external_id' => 'po-1', 'status' => 'consumed']);
        $this->assertSame('2575.00', $ok->json('lines.0.total_cost'));
        $this->assertSame('41.20', $ok->json('lines.0.unit_cost'));
        $this->assertSame('1388.75', $ok->json('lines.1.total_cost'));
        $this->assertSame('3963.75', $ok->json('total_cost'));
        $this->assertSame('L-7731', $ok->json('lines.0.batches.0.batch_number'));

        $short = $this->postJson(self::BASE.'/production-orders/po-2/consume', ['lines' => [['product_uuid' => self::GLYCERIN, 'quantity' => '120.000001', 'uom' => 'kg']]], $this->auth('c2'));
        $short->assertStatus(422)->assertJsonPath('shortfalls.0.available', '120.000000')->assertJsonStructure(['message', 'errors' => ['lines.0.quantity']]);

        $this->postJson(self::BASE.'/production-orders/po-3/consume', ['lines' => [['product_uuid' => '90000000-0000-4000-8000-000000000009', 'quantity' => '1']]], $this->auth('c3'))->assertStatus(422);
        $this->postJson(self::BASE.'/production-orders/po-4/consume', ['lines' => [['product_uuid' => self::GLYCERIN, 'quantity' => 62.5]]], $this->auth('c4'))->assertStatus(422);
        $this->postJson(self::BASE.'/production-orders/po-5/consume', ['lines' => []], $this->auth('c5'))->assertStatus(422);
    }

    public function test_the_same_order_with_a_different_body_is_a_409_and_the_same_body_is_a_no_op(): void
    {
        $body = ['lines' => [['product_uuid' => self::GLYCERIN, 'quantity' => '1.000000']]];
        $this->postJson(self::BASE.'/production-orders/po-1/consume', $body, $this->auth('a'))->assertOk();

        $this->postJson(self::BASE.'/production-orders/po-1/consume', $body, $this->auth('b'))->assertOk()->assertJson(['status' => 'consumed']);
        $this->postJson(self::BASE.'/production-orders/po-1/consume', ['lines' => [['product_uuid' => self::GLYCERIN, 'quantity' => '2.000000']]], $this->auth('c'))->assertStatus(409);
    }

    public function test_receive_needs_a_consumed_order_and_a_finished_good(): void
    {
        $receive = ['finished_good_uuid' => self::FG, 'batch_number' => 'B-1', 'quantity' => '500.000000', 'unit_cost' => '18.457300', 'production_date' => '2026-11-25', 'expiry_date' => '2028-11-24'];

        $this->postJson(self::BASE.'/production-orders/po-1/receive', $receive, $this->auth('r0'))->assertStatus(409);

        $this->postJson(self::BASE.'/production-orders/po-1/consume', ['lines' => [['product_uuid' => self::GLYCERIN, 'quantity' => '1.000000']]], $this->auth('r1'))->assertOk();
        $this->postJson(self::BASE.'/production-orders/po-1/receive', ['finished_good_uuid' => self::GLYCERIN] + $receive, $this->auth('r2'))->assertStatus(422);

        $done = $this->postJson(self::BASE.'/production-orders/po-1/receive', $receive, $this->auth('r3'))->assertOk();
        $done->assertJson(['external_id' => 'po-1', 'status' => 'received', 'quantity' => '500.000000'])->assertJsonStructure(['product_batch_uuid', 'stock_movement_uuid']);

        $this->postJson(self::BASE.'/production-orders/po-1/receive', $receive, $this->auth('r4'))->assertOk()
            ->assertJsonPath('product_batch_uuid', $done->json('product_batch_uuid'));
        $this->postJson(self::BASE.'/production-orders/po-1/receive', ['quantity' => '1'] + $receive, $this->auth('r5'))->assertStatus(409);
        $this->postJson(self::BASE.'/production-orders/po-9/receive', ['expiry_date' => '2020-01-01'] + $receive, $this->auth('r6'))->assertStatus(422);
    }

    public function test_the_receive_alias_product_uuid_is_accepted_like_sanad_does(): void
    {
        $this->postJson(self::BASE.'/production-orders/po-1/consume', ['lines' => [['product_uuid' => self::GLYCERIN, 'quantity' => '1']]], $this->auth('x1'))->assertOk();

        $this->postJson(self::BASE.'/production-orders/po-1/receive', [
            'product_uuid' => self::FG, 'batch_number' => 'B-1', 'quantity' => '5', 'unit_cost' => '1', 'production_date' => '2026-11-25',
        ], $this->auth('x2'))->assertOk();
    }
}
