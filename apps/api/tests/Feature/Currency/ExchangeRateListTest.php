<?php

namespace Tests\Feature\Currency;

use App\Models\ExchangeRate;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExchangeRateListTest extends TestCase
{
    use RefreshDatabase;

    private Source $cbe;

    private Source $bank;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-21 09:00:00');
        $this->cbe = Source::factory()->create(['title' => 'CBE']);
        $this->bank = Source::factory()->create(['title' => 'Bank quote']);

        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '48', 'rate_date' => '2026-11-18', 'source_id' => $this->cbe->id]);
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '49', 'rate_date' => '2026-11-20', 'source_id' => $this->cbe->id]);
        ExchangeRate::factory()->create(['from_currency' => 'EUR', 'rate' => '52', 'rate_date' => '2026-11-19', 'source_id' => $this->bank->id, 'confidence' => 'L6_community']);
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '99', 'rate_date' => '2026-12-31', 'source_id' => $this->cbe->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function rows(array $query = []): array
    {
        $rows = [];
        $this->get(route('settings.exchange-rates.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$rows): void {
            $rows = $page->toArray()['props']['list']['rows'];
        });

        return $rows;
    }

    public function test_it_renders_the_settings_page_with_the_list_the_latest_rates_and_the_form_options(): void
    {
        $this->get(route('settings.exchange-rates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/ExchangeRates')
            ->has('list.rows', 4)
            ->where('list.meta.total', 4)
            ->has('list.schema.filters', 4)
            ->where('list.schema.defaults.sort', 'date')
            ->where('list.schema.defaults.dir', 'desc')
            ->has('latest', 6)
            ->where('currencyOptions.0.value', 'USD')
            ->has('confidenceOptions', 7)
            ->has('sourceKindOptions')
            ->where('urls.lookup', '/settings/exchange-rates/lookup')
            ->where('prefill', null));
    }

    public function test_the_rows_carry_what_the_table_shows(): void
    {
        $row = $this->rows(['currency' => 'EUR'])[0];

        $this->assertSame('EUR', $row['currency']);
        $this->assertSame('52', $row['rate']);
        $this->assertSame('2026-11-19', $row['rate_date']);
        $this->assertSame('Bank quote', $row['source_title']);
        $this->assertSame($this->bank->id, $row['source_id']);
        $this->assertSame('L6_community', $row['confidence']);
    }

    public function test_it_lists_the_newest_date_first_by_default(): void
    {
        $this->assertSame(['2026-12-31', '2026-11-20', '2026-11-19', '2026-11-18'], array_column($this->rows(), 'rate_date'));
    }

    public function test_it_filters_by_currency(): void
    {
        $this->assertSame(['EUR'], array_unique(array_column($this->rows(['currency' => 'EUR']), 'currency')));
        $this->assertCount(3, $this->rows(['currency' => 'USD']));
        $this->assertCount(4, $this->rows(['currency' => 'JPY']), 'an unknown value is ignored, not passed to the database');
    }

    public function test_it_filters_by_date_range_inclusively(): void
    {
        $this->assertSame(['2026-11-20', '2026-11-19'], array_column($this->rows(['date_from' => '2026-11-19', 'date_to' => '2026-11-20']), 'rate_date'));
        $this->assertSame(['2026-12-31'], array_column($this->rows(['date_from' => '2026-12-01']), 'rate_date'));
    }

    public function test_it_filters_by_source_and_confidence(): void
    {
        $this->assertSame(['EUR'], array_column($this->rows(['source' => $this->bank->id]), 'currency'));
        $this->assertCount(3, $this->rows(['source' => $this->cbe->id]));
        $this->assertSame(['EUR'], array_column($this->rows(['confidence' => 'L6_community']), 'currency'));
    }

    public function test_it_searches_by_source_title_and_currency_code(): void
    {
        $this->assertSame(['EUR'], array_column($this->rows(['search' => 'bank']), 'currency'));
        $this->assertSame(['EUR'], array_column($this->rows(['search' => 'eur']), 'currency'));
        $this->assertSame([], $this->rows(['search' => 'nothing like this']));
    }

    public function test_it_sorts_by_rate_and_currency_in_either_direction(): void
    {
        $this->assertSame(['48', '49', '52', '99'], array_column($this->rows(['sort' => 'rate', 'dir' => 'asc']), 'rate'));
        $this->assertSame(['USD', 'USD', 'USD', 'EUR'], array_column($this->rows(['sort' => 'currency', 'dir' => 'desc']), 'currency'));
        $this->assertSame(['2026-11-18'], array_slice(array_column($this->rows(['sort' => 'date', 'dir' => 'asc']), 'rate_date'), 0, 1));
    }

    public function test_the_latest_summary_shows_the_newest_rate_up_to_today_and_none_where_there_is_none(): void
    {
        $this->get(route('settings.exchange-rates.index'))->assertInertia(function (Assert $page): void {
            $latest = collect($page->toArray()['props']['latest'])->keyBy('currency');

            $this->assertSame(['USD', 'EUR', 'CNY', 'SAR', 'AED', 'GBP'], $latest->keys()->all());
            $this->assertSame('49', $latest['USD']['rate']['rate'], 'the 2026-12-31 rate is in the future');
            $this->assertSame('2026-11-20', $latest['USD']['rate']['rate_date']);
            $this->assertSame('CBE', $latest['USD']['rate']['source_title']);
            $this->assertSame('52', $latest['EUR']['rate']['rate']);
            $this->assertNull($latest['CNY']['rate']);
            $this->assertNull($latest['GBP']['rate']);
        });
    }

    public function test_the_page_is_empty_without_rates_and_nothing_is_invented(): void
    {
        ExchangeRate::query()->delete();

        $this->get(route('settings.exchange-rates.index'))->assertInertia(fn (Assert $page) => $page
            ->has('list.rows', 0)
            ->where('list.meta.total', 0)
            ->where('latest', fn ($latest) => collect($latest)->every(fn ($entry) => $entry['rate'] === null)));
    }

    public function test_a_link_can_open_the_add_form_for_a_currency_and_date(): void
    {
        $this->get(route('settings.exchange-rates.index', ['new_currency' => 'USD', 'new_date' => '2026-11-20']))
            ->assertInertia(fn (Assert $page) => $page->where('prefill', ['currency' => 'USD', 'rate_date' => '2026-11-20']));

        $this->get(route('settings.exchange-rates.index', ['new_currency' => 'EGP']))->assertSessionHasErrors('new_currency');
    }

    public function test_it_pages_the_list(): void
    {
        $this->get(route('settings.exchange-rates.index', ['per' => 10]))->assertInertia(fn (Assert $page) => $page->where('list.meta.per_page', 10));
        $this->get(route('settings.exchange-rates.index', ['per' => 7]))->assertInertia(fn (Assert $page) => $page->where('list.meta.per_page', config('currencies.list_per_page')));
    }
}
