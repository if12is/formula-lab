<?php

namespace Tests\Feature\Sanad;

use App\Services\Dev\MockSanadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\fakes\sanad\SanadContract;
use Tests\TestCase;

/** The mock Sanad answers the price list endpoints of contract section 8 (8.2 to 8.5) the way the real Sanad does. */
class MockSanadPriceListTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/__mock/sanad/api/v1/manufacturing';

    private const LIST = 'a1000000-0000-4000-8000-000000000001';

    private const FG = '44444444-4444-4444-8444-444444444444';

    private const GLYCERIN = '11111111-1111-4111-8111-111111111111';

    private const CUSTOMER = 'c0000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        app(MockSanadService::class)->reset();
    }

    /** @return array<string, string> */
    private function auth(string $key = 'k-1', ?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? config('sanad.mock.token')), 'Idempotency-Key' => $key, 'Accept' => 'application/json'];
    }

    public function test_the_customers_feed_has_only_the_pricing_fields_in_the_envelope_and_a_tombstone(): void
    {
        $page = $this->getJson(self::BASE.'/customers', $this->auth(token: config('sanad.mock.read_only_token')))->assertOk()->json();

        SanadContract::assertPage($page);
        $this->assertCount(4, $page['data']);
        SanadContract::assertCustomer($page['data'][0]);
        SanadContract::assertTombstone($page['data'][3]);
        $this->assertTrue($page['data'][0]['is_wholesale']);
    }

    public function test_the_customers_feed_pages_by_cursor_and_filters_by_updated_since(): void
    {
        config(['sanad.mock.page_size' => 2]);
        $first = $this->getJson(self::BASE.'/customers', $this->auth())->assertOk()->json();
        $this->assertCount(2, $first['data']);
        $this->assertNotNull($first['next_cursor']);

        $second = $this->getJson(self::BASE.'/customers?cursor='.$first['next_cursor'], $this->auth())->assertOk()->json();
        $this->assertSame(['c0000000-0000-4000-8000-000000000003', 'c0000000-0000-4000-8000-000000000004'], array_column($second['data'], 'uuid'));
        $this->assertNull($second['next_cursor']);

        $since = $this->getJson(self::BASE.'/customers?updated_since=2026-11-20T10:00:00Z', $this->auth())->json('data');
        $this->assertCount(2, $since);
    }

    public function test_creating_a_list_with_prices_returns_201_and_the_stored_two_place_values(): void
    {
        $body = ['name' => 'تجار القاهرة', 'currency' => 'EGP', 'is_active' => true, 'items' => [['product_uuid' => self::FG, 'price' => '31'], ['product_uuid' => self::GLYCERIN, 'price' => null]]];

        $response = $this->putJson(self::BASE.'/price-lists/'.self::LIST, $body, $this->auth())->assertStatus(201);

        $json = $response->json();
        SanadContract::assertPriceListResponse($json);
        $this->assertTrue($json['created']);
        $this->assertSame([['product_uuid' => self::FG, 'price' => '31.00']], $json['items']);
        $this->assertSame([self::GLYCERIN], $json['removed']);
        $this->assertSame(1, $json['items_count']);
        $this->assertSame('EGP', $json['currency']);
    }

    public function test_updating_a_list_returns_200_never_deletes_unmentioned_items_and_a_null_removes(): void
    {
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L', 'items' => [['product_uuid' => self::FG, 'price' => '31.00'], ['product_uuid' => self::GLYCERIN, 'price' => '5']]], $this->auth('k-1'))->assertStatus(201);

        $omitted = $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L2'], $this->auth('k-2'))->assertOk()->json();
        $this->assertFalse($omitted['created']);
        $this->assertSame(2, $omitted['items_count'], 'omitted items leave the stored ones alone');

        $removed = $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L2', 'items' => [['product_uuid' => self::GLYCERIN, 'price' => null]]], $this->auth('k-3'))->assertOk()->json();
        $this->assertSame(1, $removed['items_count']);
        $this->assertSame([self::GLYCERIN], $removed['removed']);
    }

    public function test_bad_prices_currency_and_unknown_products_are_422_and_atomic(): void
    {
        foreach ([31.5, '0', '-5', 'abc'] as $index => $price) {
            $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L', 'items' => [['product_uuid' => self::FG, 'price' => $price]]], $this->auth("bad-{$index}"))
                ->assertStatus(422)->assertJsonValidationErrors(['items.0.price']);
        }
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L', 'currency' => 'USD'], $this->auth('usd'))->assertStatus(422)->assertJsonValidationErrors(['currency']);
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L', 'items' => [['product_uuid' => self::FG, 'price' => '1'], ['product_uuid' => '99999999-9999-4999-8999-999999999999', 'price' => '1']]], $this->auth('unknown'))
            ->assertStatus(422)->assertJsonValidationErrors(['items.1.product_uuid']);

        $this->putJson(self::BASE.'/price-lists/'.self::LIST.'/items', ['items' => [['product_uuid' => self::FG, 'price' => '1']]], $this->auth('after'))
            ->assertStatus(404)->assertJson(['message' => 'قائمة الأسعار غير موجودة.']);
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['items' => []], $this->auth('noname'))->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_the_price_must_be_present_and_at_most_500_rows_are_taken(): void
    {
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L', 'items' => [['product_uuid' => self::FG]]], $this->auth('present'))->assertStatus(422)->assertJsonValidationErrors(['items.0.price']);

        $rows = array_map(fn (int $i): array => ['product_uuid' => sprintf('50000000-0000-4000-8000-%012d', $i), 'price' => '1'], range(1, 501));
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L', 'items' => $rows], $this->auth('many'))->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_the_items_only_endpoint_needs_1_to_500_rows_and_answers_created_false(): void
    {
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L'], $this->auth('k-1'))->assertStatus(201);

        $this->putJson(self::BASE.'/price-lists/'.self::LIST.'/items', ['items' => []], $this->auth('k-2'))->assertStatus(422);
        $ok = $this->putJson(self::BASE.'/price-lists/'.self::LIST.'/items', ['items' => [['product_uuid' => self::FG, 'price' => '12.345']]], $this->auth('k-3'))->assertOk()->json();

        SanadContract::assertPriceListResponse($ok);
        $this->assertFalse($ok['created']);
        $this->assertSame('12.35', $ok['items'][0]['price'], 'Sanad rounds to 2 dp');
    }

    public function test_assigning_a_customer_returns_the_customer_and_detaching_needs_an_explicit_null(): void
    {
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L'], $this->auth('l'))->assertStatus(201);

        $assigned = $this->putJson(self::BASE.'/customers/'.self::CUSTOMER.'/price-list', ['price_list_uuid' => self::LIST], $this->auth('a'))->assertOk()->json();
        SanadContract::assertCustomer($assigned);
        $this->assertSame(self::LIST, $assigned['price_list_uuid']);

        $feed = collect($this->getJson(self::BASE.'/customers', $this->auth())->json('data'))->firstWhere('uuid', self::CUSTOMER);
        $this->assertSame(self::LIST, $feed['price_list_uuid'], 'the feed shows the new assignment');

        $this->putJson(self::BASE.'/customers/'.self::CUSTOMER.'/price-list', [], $this->auth('missing'))->assertStatus(422)->assertJsonValidationErrors(['price_list_uuid']);
        $detached = $this->putJson(self::BASE.'/customers/'.self::CUSTOMER.'/price-list', ['price_list_uuid' => null], $this->auth('d'))->assertOk()->json();
        $this->assertNull($detached['price_list_uuid']);
    }

    public function test_assignment_errors_unknown_customer_unknown_list_and_inactive_list(): void
    {
        $this->putJson(self::BASE.'/customers/c9999999-9999-4999-8999-999999999999/price-list', ['price_list_uuid' => null], $this->auth('c'))->assertStatus(404)->assertJson(['message' => 'العميل غير موجود.']);
        $this->putJson(self::BASE.'/customers/'.self::CUSTOMER.'/price-list', ['price_list_uuid' => self::LIST], $this->auth('u'))->assertStatus(422)->assertJsonValidationErrors(['price_list_uuid']);

        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L', 'is_active' => false], $this->auth('off'))->assertStatus(201);
        $this->putJson(self::BASE.'/customers/'.self::CUSTOMER.'/price-list', ['price_list_uuid' => self::LIST], $this->auth('i'))->assertStatus(422)->assertJsonValidationErrors(['price_list_uuid']);
    }

    public function test_the_price_list_endpoints_need_write_ability_and_an_idempotency_key(): void
    {
        $readOnly = config('sanad.mock.read_only_token');
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L'], $this->auth(token: $readOnly))->assertStatus(403);
        $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L'], ['Authorization' => 'Bearer '.config('sanad.mock.token')])->assertStatus(400);

        $first = $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L'], $this->auth('same'));
        $replay = $this->putJson(self::BASE.'/price-lists/'.self::LIST, ['name' => 'L'], $this->auth('same'));
        $first->assertStatus(201);
        $replay->assertStatus(201)->assertHeader('Idempotent-Replay', 'true');
    }
}
