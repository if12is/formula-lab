<?php

namespace Tests\Feature\Sanad;

use App\Exceptions\Domain\SanadIntegrationException;
use App\Integrations\Sanad\SanadClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fakes\sanad\SanadFake;
use Tests\TestCase;

class SanadClientTest extends TestCase
{
    private SanadClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        SanadFake::use();
        $this->client = new SanadClient;
    }

    public function test_reads_send_the_bearer_token_json_headers_and_the_filters(): void
    {
        SanadFake::products([SanadFake::product(['uuid' => 'p1'])], 'next-1');

        $page = $this->client->products('cur-1', 'raw_material', '2026-11-01T00:00:00Z');

        $this->assertSame('p1', $page->data[0]['uuid']);
        $this->assertSame('next-1', $page->nextCursor);
        $this->assertTrue($page->hasMore());
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), SanadFake::BASE_URL.'/api/v1/manufacturing/products?')
                && $request->hasHeader('Authorization', 'Bearer '.SanadFake::TOKEN)
                && $request->hasHeader('Accept', 'application/json')
                && ! $request->hasHeader('Idempotency-Key')
                && $query === ['cursor' => 'cur-1', 'kind' => 'raw_material', 'updated_since' => '2026-11-01T00:00:00Z'];
        });
    }

    public function test_null_filters_are_not_sent_and_the_last_page_has_no_cursor(): void
    {
        SanadFake::suppliers([SanadFake::supplier()]);

        $page = $this->client->suppliers();

        $this->assertNull($page->nextCursor);
        $this->assertFalse($page->hasMore());
        Http::assertSent(fn (Request $request): bool => $request->url() === SanadFake::BASE_URL.'/api/v1/manufacturing/suppliers');
    }

    public function test_purchases_and_stock_use_their_endpoints(): void
    {
        SanadFake::purchases([SanadFake::purchase()]);
        SanadFake::stock([['product_uuid' => 'p', 'branch_code' => null, 'quantity' => '1.000000', 'uom' => 'kg', 'updated_at' => '2026-11-21T09:30:00Z']]);

        $this->assertCount(1, $this->client->purchases('2026-11-01T00:00:00Z')->data);
        $this->assertCount(1, $this->client->stock(branch: 'MAIN')->data);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/purchases?updated_since='));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stock?branch=MAIN'));
    }

    public function test_writes_send_an_idempotency_key_and_the_body_with_decimal_strings(): void
    {
        SanadFake::upsertProduct();
        $body = ['name' => 'Glycerin', 'item_kind' => 'raw_material', 'is_sellable' => false, 'uom' => 'kg', 'cost_price' => '41.2000'];

        $response = $this->client->upsertProduct('abc', $body);

        $this->assertSame('abc', $response['uuid']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === SanadFake::BASE_URL.'/api/v1/manufacturing/products/abc'
            && $request->hasHeader('Idempotency-Key')
            && $request->data() === $body);
    }

    public function test_the_default_idempotency_key_is_deterministic_and_an_explicit_one_wins(): void
    {
        SanadFake::consume();
        $lines = [['product_uuid' => 'p', 'quantity' => '62.500000', 'uom' => 'kg']];

        $this->client->consume('po-1', $lines);
        $this->client->consume('po-1', $lines);
        $this->client->consume('po-2', $lines);
        $this->client->consume('po-1', $lines, idempotencyKey: 'explicit');

        $keys = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])->all();
        $this->assertSame($keys[0], $keys[1]);
        $this->assertNotSame($keys[0], $keys[2]);
        $this->assertSame('explicit', $keys[3]);
    }

    public function test_consume_receive_and_wholesale_prices_hit_the_contract_paths(): void
    {
        SanadFake::consume();
        SanadFake::receive();
        SanadFake::wholesalePrices();

        $consumed = $this->client->consume('po-1', [['product_uuid' => 'p', 'quantity' => '1.000000', 'uom' => 'kg']], ['branch_code' => 'MAIN', 'consumed_on' => '2026-11-25']);
        $received = $this->client->receive('po-1', ['finished_good_uuid' => 'fg', 'quantity' => '500.000000', 'unit_cost' => '18.457300']);
        $prices = $this->client->wholesalePrices('fg', ['wholesale_price' => '31.00', 'tier' => 'distributors']);

        $this->assertSame('consumed', $consumed['status']);
        $this->assertSame('received', $received['status']);
        $this->assertSame('500.000000', $received['quantity']);
        $this->assertSame('31.00', $prices['wholesale_price']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/production-orders/po-1/consume') && $request->data()['branch_code'] === 'MAIN');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/production-orders/po-1/receive'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/products/fg/wholesale-prices'));
    }

    public function test_floats_are_refused_before_anything_is_sent(): void
    {
        SanadFake::consume();

        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->client->consume('po-1', [['product_uuid' => 'p', 'quantity' => 62.5]]), 'lines.0.quantity');
        Http::assertNothingSent();
    }

    public function test_an_unconfigured_client_fails_without_calling_out(): void
    {
        config(['sanad.client.base_url' => null]);

        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->client->products());
        Http::assertNothingSent();
    }

    /** @return array<string, array{0: int}> */
    public static function errorStatuses(): array
    {
        return ['malformed' => [400], 'unauthorized' => [401], 'forbidden' => [403], 'not found' => [404], 'conflict' => [409], 'validation' => [422], 'server error' => [500]];
    }

    #[DataProvider('errorStatuses')]
    public function test_errors_map_to_a_domain_exception_with_a_translated_message(int $status): void
    {
        SanadFake::fail('consume', $status, 'remote text', ['errors' => ['lines.0.quantity' => ['short']]]);

        try {
            $this->client->consume('po-1', [['product_uuid' => 'p', 'quantity' => '1.000000', 'uom' => 'kg']]);
            $this->fail('expected exception');
        } catch (SanadIntegrationException $exception) {
            $this->assertSame($status, $exception->status);
            $this->assertSame('remote text', $exception->remoteMessage);
            $this->assertNotSame('', $exception->getMessage());
            $this->assertStringNotContainsString('remote text', $exception->getMessage());
            $this->assertSame(['lines.0.quantity' => ['short']], $exception->errors);
            Http::assertSentCount(1);
        }
    }

    public function test_an_insufficient_stock_422_exposes_the_shortfalls(): void
    {
        $shortfalls = [['product_uuid' => 'p', 'required' => '62.5', 'available' => '40.0']];
        SanadFake::fail('consume', 422, 'short', ['shortfalls' => $shortfalls]);

        try {
            $this->client->consume('po-1', [['product_uuid' => 'p', 'quantity' => '62.500000', 'uom' => 'kg']]);
            $this->fail('expected exception');
        } catch (SanadIntegrationException $exception) {
            $this->assertSame($shortfalls, $exception->shortfalls());
        }
    }

    public function test_the_error_messages_follow_the_locale(): void
    {
        SanadFake::fail('products', 401, 'x');
        $messages = [];
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            try {
                $this->client->products();
            } catch (SanadIntegrationException $exception) {
                $messages[$locale] = $exception->getMessage();
            }
        }

        $this->assertNotSame($messages['en'], $messages['ar']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $messages['ar']);
    }

    public function test_503_is_retried_with_the_same_key_until_it_succeeds(): void
    {
        $sequence = Http::sequence()->pushStatus(503)->pushStatus(503)->push(['uuid' => 'u', 'item_kind' => 'raw_material', 'is_sellable' => false, 'updated_at' => 'x']);
        SanadFake::respond('upsertProduct', $sequence);

        $response = $this->client->upsertProduct('u', ['name' => 'X', 'item_kind' => 'raw_material', 'uom' => 'kg']);

        $this->assertSame('u', $response['uuid']);
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::usleep(1_000_000), Sleep::usleep(2_000_000)]);
        $keys = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertCount(1, $keys);
    }

    public function test_429_is_retried_and_exhausting_the_attempts_surfaces_the_error(): void
    {
        SanadFake::fail('upsertProduct', 429, 'slow down', headers: ['Retry-After' => '3']);

        try {
            $this->client->upsertProduct('u', ['name' => 'X', 'item_kind' => 'raw_material', 'uom' => 'kg']);
            $this->fail('expected exception');
        } catch (SanadIntegrationException $exception) {
            $this->assertSame(429, $exception->status);
            Http::assertSentCount(config('sanad.client.max_attempts'));
            Sleep::assertSleptTimes(config('sanad.client.max_attempts') - 1);
        }
    }

    public function test_4xx_other_than_429_is_not_retried(): void
    {
        SanadFake::fail('upsertProduct', 422, 'bad');

        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->client->upsertProduct('u', ['name' => 'X']));
        Http::assertSentCount(1);
    }

    public function test_network_failures_are_retried_then_mapped(): void
    {
        $attempts = 0;
        SanadFake::respond('products', function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 7: refused');
        });

        try {
            $this->client->products();
            $this->fail('expected exception');
        } catch (SanadIntegrationException $exception) {
            $this->assertSame(0, $exception->status);
            $this->assertStringContainsString('refused', $exception->getMessage());
            $this->assertSame(config('sanad.client.max_attempts'), $attempts);
        }
    }

    public function test_backoff_is_exponential_capped_and_honours_retry_after(): void
    {
        $milliseconds = array_map(fn (int $attempt): int => $this->client->backoffMilliseconds($attempt), range(1, 8));

        $this->assertSame([1000, 2000, 4000, 8000, 16000, 32000, 60000, 60000], $milliseconds);
        $this->assertSame(10000, $this->client->backoffMilliseconds(1, 10));
        $this->assertSame(2000, $this->client->backoffMilliseconds(2, 1));
        $this->assertSame(60000, $this->client->backoffMilliseconds(1, 3600));
    }

    public function test_a_non_json_success_is_an_unexpected_response(): void
    {
        SanadFake::respond('products', Http::response('<html>', 200));

        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->client->products());
    }

    public function test_the_fake_serves_the_shared_fixtures_filtered_by_kind(): void
    {
        SanadFake::products(SanadFake::fixture('products'));

        $rows = $this->client->products(kind: 'raw_material')->data;

        $this->assertCount(3, $rows);
        $this->assertTrue($rows[2]['deleted']);
        $this->assertArrayNotHasKey('_item_kind', $rows[2]);
    }
}
