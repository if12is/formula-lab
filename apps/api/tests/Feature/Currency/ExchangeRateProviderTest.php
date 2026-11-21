<?php

namespace Tests\Feature\Currency;

use App\Exceptions\Domain\ExchangeRateException;
use App\Models\ExchangeRate;
use App\Models\Source;
use App\Services\Currency\ExchangeRateService;
use App\Services\Currency\Providers\ExchangeRateProvider;
use App\Services\Currency\Providers\ManualExchangeRateProvider;
use App\Support\DTOs\ExchangeRateQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateProviderTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<ExchangeRateQuote>  $quotes */
    private function provider(array $quotes): ExchangeRateProvider
    {
        return new class($quotes) implements ExchangeRateProvider
        {
            /** @param  list<ExchangeRateQuote>  $quotes */
            public function __construct(private array $quotes) {}

            public function key(): string
            {
                return 'fake';
            }

            public function fetch(string $date): array
            {
                return array_values(array_filter($this->quotes, fn (ExchangeRateQuote $quote): bool => $quote->rateDate === $date));
            }
        };
    }

    public function test_the_manual_provider_is_the_configured_default_and_has_nothing_to_fetch(): void
    {
        $provider = app(ExchangeRateProvider::class);

        $this->assertInstanceOf(ManualExchangeRateProvider::class, $provider);
        $this->assertSame('manual', $provider->key());
        $this->assertSame([], $provider->fetch('2026-11-20'));
    }

    public function test_the_provider_is_a_config_choice(): void
    {
        config(['currencies.providers.cbe' => ManualExchangeRateProvider::class, 'currencies.provider' => 'cbe']);

        $this->assertInstanceOf(ManualExchangeRateProvider::class, app(ExchangeRateProvider::class));
    }

    public function test_importing_from_the_manual_provider_stores_nothing(): void
    {
        $stored = app(ExchangeRateService::class)->importFrom(new ManualExchangeRateProvider, '2026-11-20');

        $this->assertSame([], $stored);
        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_importing_stores_what_a_provider_supplies_through_the_one_write_path(): void
    {
        $source = Source::factory()->create(['title' => 'CBE']);
        $provider = $this->provider([
            new ExchangeRateQuote('USD', '48.50', '2026-11-20', $source->id, 'L4_regulatory_db'),
            new ExchangeRateQuote('EUR', '52', '2026-11-20', $source->id, 'L4_regulatory_db'),
            new ExchangeRateQuote('GBP', '61', '2026-11-19', $source->id, 'L4_regulatory_db'),
        ]);

        $stored = app(ExchangeRateService::class)->importFrom($provider, '2026-11-20');

        $this->assertCount(2, $stored);
        $this->assertSame(['USD', 'EUR'], array_map(fn (ExchangeRate $rate): string => $rate->from_currency, $stored));
        $this->assertSame('48.5', ExchangeRate::query()->where('from_currency', 'USD')->sole()->rate);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'exchange_rates', 'action' => 'create']);
    }

    public function test_importing_twice_keeps_what_is_stored_and_adds_only_new_rates(): void
    {
        $source = Source::factory()->create();
        $service = app(ExchangeRateService::class);
        $first = $this->provider([new ExchangeRateQuote('USD', '48', '2026-11-20', $source->id, 'L4_regulatory_db')]);
        $second = $this->provider([
            new ExchangeRateQuote('USD', '49', '2026-11-20', $source->id, 'L4_regulatory_db'),
            new ExchangeRateQuote('EUR', '52', '2026-11-20', $source->id, 'L4_regulatory_db'),
        ]);

        $service->importFrom($first, '2026-11-20');
        $stored = $service->importFrom($second, '2026-11-20');

        $this->assertCount(1, $stored);
        $this->assertSame('48', ExchangeRate::query()->where('from_currency', 'USD')->sole()->rate, 'a stored rate is never overwritten by an import');
        $this->assertDatabaseCount('exchange_rates', 2);
    }

    public function test_a_provider_cannot_smuggle_in_the_base_or_an_unsupported_currency(): void
    {
        $source = Source::factory()->create();

        $this->expectException(ExchangeRateException::class);

        try {
            app(ExchangeRateService::class)->importFrom($this->provider([new ExchangeRateQuote('EGP', '1', '2026-11-20', $source->id, 'L4_regulatory_db')]), '2026-11-20');
        } finally {
            $this->assertDatabaseCount('exchange_rates', 0);
        }
    }

    public function test_the_quote_is_plain_data(): void
    {
        $quote = new ExchangeRateQuote('USD', '48.5', '2026-11-20', 'source-id', 'L4_regulatory_db');

        $this->assertSame(['from_currency' => 'USD', 'rate' => '48.5', 'rate_date' => '2026-11-20', 'source_id' => 'source-id', 'confidence' => 'L4_regulatory_db'], $quote->toArray());
    }
}
