<?php

namespace Tests\Feature\Sanad;

use App\Exceptions\Domain\SanadIntegrationException;
use App\Integrations\Sanad\SanadClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\fakes\sanad\SanadContract;
use Tests\fakes\sanad\SanadFake;
use Tests\TestCase;

/** The price list calls of the LAN client (contract 8.2 to 8.5): paths, the idempotency key, decimal strings and the error mapping. */
class SanadClientPriceListTest extends TestCase
{
    private const LIST = 'a1000000-0000-4000-8000-000000000001';

    private const PRODUCT = '44444444-4444-4444-8444-444444444444';

    private const CUSTOMER = 'c1000000-0000-4000-8000-000000000001';

    private SanadClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        SanadFake::use();
        $this->client = new SanadClient;
    }

    public function test_the_upsert_is_a_put_to_the_list_uuid_with_an_idempotency_key_and_string_prices(): void
    {
        SanadFake::upsertPriceList();

        $body = $this->client->upsertPriceList(self::LIST, ['name' => 'تجار القاهرة', 'currency' => 'EGP', 'is_active' => true, 'items' => [['product_uuid' => self::PRODUCT, 'price' => '31']]]);

        SanadContract::assertPriceListResponse($body);
        $this->assertSame('31.00', $body['items'][0]['price'], 'Sanad returns the stored 2-place value');
        $this->assertTrue($body['created']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === SanadFake::BASE_URL.'/api/v1/manufacturing/price-lists/'.self::LIST
            && $request->hasHeader('Authorization', 'Bearer '.SanadFake::TOKEN)
            && preg_match('/^[0-9a-f]{64}$/', $request->header('Idempotency-Key')[0]) === 1
            && $request['items'][0]['price'] === '31');
    }

    public function test_the_same_request_reuses_the_same_idempotency_key(): void
    {
        SanadFake::upsertPriceList();
        $data = ['name' => 'X', 'items' => [['product_uuid' => self::PRODUCT, 'price' => '31.00']]];

        $this->client->upsertPriceList(self::LIST, $data);
        $this->client->upsertPriceList(self::LIST, $data);

        $keys = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0]);
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_a_float_price_is_refused_before_it_is_sent(): void
    {
        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->client->upsertPriceList(self::LIST, ['name' => 'X', 'items' => [['product_uuid' => self::PRODUCT, 'price' => 31.5]]]));
        Http::assertNothingSent();
    }

    public function test_items_only_and_the_null_price_that_removes_a_product(): void
    {
        SanadFake::upsertPriceListItems();

        $body = $this->client->upsertPriceListItems(self::LIST, [['product_uuid' => self::PRODUCT, 'price' => null]]);

        SanadContract::assertPriceListResponse($body, withItemsCount: false);
        $this->assertSame([self::PRODUCT], $body['removed']);
        $this->assertFalse($body['created']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/price-lists/'.self::LIST.'/items') && $request['items'][0]['price'] === null);
    }

    public function test_assigning_and_detaching_a_customer_sends_the_list_uuid_or_null(): void
    {
        SanadFake::assignCustomerPriceList();

        $assigned = $this->client->assignCustomerPriceList(self::CUSTOMER, self::LIST);
        $detached = $this->client->assignCustomerPriceList(self::CUSTOMER, null);

        SanadContract::assertCustomer($assigned);
        $this->assertSame(self::LIST, $assigned['price_list_uuid']);
        $this->assertNull($detached['price_list_uuid']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/customers/'.self::CUSTOMER.'/price-list') && $request->data() === ['price_list_uuid' => null]);
    }

    public function test_the_customers_feed_is_read_with_the_cursor(): void
    {
        SanadFake::customers([SanadFake::customer(['uuid' => self::CUSTOMER])], 'next-1');

        $page = $this->client->customers('cur-1', '2026-11-01T00:00:00Z');

        $this->assertSame(self::CUSTOMER, $page->data[0]['uuid']);
        $this->assertSame('next-1', $page->nextCursor);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), SanadFake::BASE_URL.'/api/v1/manufacturing/customers?') && $query === ['cursor' => 'cur-1', 'updated_since' => '2026-11-01T00:00:00Z'];
        });
    }

    public function test_sanads_validation_and_not_found_answers_become_translated_exceptions(): void
    {
        SanadFake::fail('upsertPriceList', 422, 'items.0.product_uuid', ['errors' => ['items.0.product_uuid' => ['unknown']]]);
        SanadFake::fail('upsertPriceListItems', 404, 'قائمة الأسعار غير موجودة.');

        $validation = null;
        try {
            $this->client->upsertPriceList(self::LIST, ['name' => 'X']);
        } catch (SanadIntegrationException $exception) {
            $validation = $exception;
        }
        $this->assertNotNull($validation);
        $this->assertSame(422, $validation->status);

        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->client->upsertPriceListItems(self::LIST, [['product_uuid' => self::PRODUCT, 'price' => '1']]));
    }

    public function test_the_fake_refuses_what_sanad_refuses(): void
    {
        SanadFake::upsertPriceList();

        foreach (['0', '-1', 'abc'] as $bad) {
            $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->client->upsertPriceList(self::LIST, ['name' => 'X', 'items' => [['product_uuid' => self::PRODUCT, 'price' => $bad]]]));
        }
        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->client->upsertPriceList(self::LIST, ['name' => 'X', 'currency' => 'USD']));
    }
}
