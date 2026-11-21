<?php

namespace Tests\Feature\Currency;

use App\Enums\Confidence;
use App\Models\ExchangeRate;
use App\Models\Price;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Currency\ExchangeRateLookupService;
use App\Services\Material\MaterialDetailService;
use App\Services\Material\MaterialOptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExchangeRateLookupTest extends TestCase
{
    use RefreshDatabase;

    private ExchangeRateLookupService $lookup;

    private Source $cbe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookup = app(ExchangeRateLookupService::class);
        $this->cbe = Source::factory()->create(['title' => 'CBE']);
    }

    private function rate(string $currency, string $date, string $rate, array $overrides = []): ExchangeRate
    {
        return ExchangeRate::factory()->create(['from_currency' => $currency, 'rate_date' => $date, 'rate' => $rate, 'source_id' => $this->cbe->id, ...$overrides]);
    }

    public function test_it_picks_the_rate_of_the_day_itself(): void
    {
        $this->rate('USD', '2026-11-18', '48');
        $this->rate('USD', '2026-11-20', '49');

        $found = $this->lookup->rateOn('USD', '2026-11-20');

        $this->assertSame('49', $found['rate']);
        $this->assertSame('2026-11-20', $found['rate_date']);
        $this->assertSame('CBE', $found['source_title']);
        $this->assertSame($this->cbe->id, $found['source_id']);
        $this->assertSame('L4_regulatory_db', $found['confidence']);
        $this->assertSame('USD', $found['currency']);
    }

    public function test_it_picks_the_latest_rate_on_or_before_the_day(): void
    {
        $this->rate('USD', '2026-11-10', '47');
        $this->rate('USD', '2026-11-18', '48');
        $this->rate('USD', '2026-11-25', '50');

        $this->assertSame('48', $this->lookup->rateOn('USD', '2026-11-22')['rate']);
        $this->assertSame('47', $this->lookup->rateOn('USD', '2026-11-17')['rate']);
    }

    public function test_it_never_uses_a_rate_dated_after_the_day_so_no_earlier_rate_means_none(): void
    {
        $this->rate('USD', '2026-11-25', '50');

        $this->assertNull($this->lookup->rateOn('USD', '2026-11-24'));
    }

    public function test_it_finds_nothing_without_any_rate_and_never_for_the_base_or_an_unsupported_currency(): void
    {
        $this->assertNull($this->lookup->rateOn('USD', '2026-11-20'));
        $this->assertNull($this->lookup->rateOn('EGP', '2026-11-20'));
        $this->assertNull($this->lookup->rateOn('JPY', '2026-11-20'));
    }

    public function test_a_rate_of_one_currency_never_answers_for_another(): void
    {
        $this->rate('USD', '2026-11-20', '48');

        $this->assertNull($this->lookup->rateOn('EUR', '2026-11-20'));
    }

    public function test_on_one_day_the_better_confidence_wins_then_the_newer_entry(): void
    {
        $other = Source::factory()->create();
        $this->rate('USD', '2026-11-20', '48', ['confidence' => Confidence::L6Community, 'source_id' => $other->id]);
        $this->rate('USD', '2026-11-20', '48.2', ['confidence' => Confidence::L4RegulatoryDb]);

        $this->assertSame('48.2', $this->lookup->rateOn('USD', '2026-11-20')['rate']);
    }

    public function test_a_tenants_own_rate_beats_a_global_one_of_the_same_day_and_other_tenants_are_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $stranger = Tenant::factory()->create();
        $other = Source::factory()->create();
        $this->rate('USD', '2026-11-20', '48');
        $this->rate('USD', '2026-11-20', '49', ['tenant_id' => $tenant->id, 'source_id' => $other->id, 'confidence' => Confidence::L6Community]);
        $this->rate('USD', '2026-11-20', '99', ['tenant_id' => $stranger->id, 'source_id' => Source::factory()->create()->id]);

        $this->assertSame('48', $this->lookup->rateOn('USD', '2026-11-20')['rate']);
        $this->assertSame('49', $this->lookup->rateOn('USD', '2026-11-20', $tenant->id)['rate']);
    }

    public function test_latest_uses_today_and_ignores_a_future_rate(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->rate('USD', '2026-11-20', '48');
        $this->rate('USD', '2026-11-22', '60');

        $this->assertSame('48', $this->lookup->latest('USD')['rate']);

        Carbon::setTestNow();
    }

    public function test_each_price_gets_the_rate_of_its_own_quote_date(): void
    {
        $this->rate('USD', '2026-01-02', '40');
        $this->rate('USD', '2026-02-01', '50');
        $material = TradeMaterial::factory()->create();
        $january = Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'USD', 'quoted_on' => '2026-01-20']);
        $march = Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'USD', 'quoted_on' => '2026-03-05']);
        $early = Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'USD', 'quoted_on' => '2025-12-31']);
        $egp = Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'EGP', 'quoted_on' => '2026-03-05']);
        $euro = Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'EUR', 'quoted_on' => '2026-03-05']);

        $rates = $this->lookup->forPrices(collect([$january, $march, $early, $egp, $euro]));

        $this->assertSame('40', $rates[$january->id]['rate']);
        $this->assertSame('50', $rates[$march->id]['rate']);
        $this->assertNull($rates[$early->id], 'no rate on or before the quote date means no rate');
        $this->assertNull($rates[$egp->id]);
        $this->assertNull($rates[$euro->id]);
    }

    public function test_the_material_options_hand_the_editor_each_price_with_its_rate(): void
    {
        $this->rate('USD', '2026-02-01', '50');
        $material = TradeMaterial::factory()->create();
        $priced = Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'USD', 'amount' => '2', 'quoted_on' => '2026-03-05']);
        $unpriced = Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'USD', 'amount' => '3', 'quoted_on' => '2026-01-05']);

        $option = app(MaterialOptionService::class)->forMaterials(collect([$material]))[$material->id];
        $byId = array_column($option['prices'], null, 'id');

        $this->assertSame('USD', $byId[$priced->id]['currency']);
        $this->assertSame('50', $byId[$priced->id]['fx']['rate']);
        $this->assertSame('CBE', $byId[$priced->id]['fx']['source_title']);
        $this->assertNull($byId[$unpriced->id]['fx']);
    }

    public function test_the_material_page_hands_the_prices_tab_each_price_with_its_rate_and_the_currency_options(): void
    {
        $this->rate('USD', '2026-02-01', '50');
        $material = TradeMaterial::factory()->create();
        Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'USD', 'quoted_on' => '2026-03-05']);
        Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'EGP', 'quoted_on' => '2026-03-05']);

        $this->get(route('materials.show', ['material' => $material, 'tab' => 'prices']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('prices', 2)
                ->where('prices', fn ($prices) => collect($prices)->firstWhere('currency', 'USD')['fx']['rate'] === '50'
                    && collect($prices)->firstWhere('currency', 'EGP')['fx'] === null)
                ->where('currencyOptions.0.value', 'EGP')
                ->has('currencyOptions', 7));
    }

    public function test_the_detail_service_reports_a_missing_rate_as_null(): void
    {
        $material = TradeMaterial::factory()->create();
        Price::factory()->create(['trade_material_id' => $material->id, 'currency' => 'USD']);

        $detail = app(MaterialDetailService::class)->detail($material);

        $this->assertNull($detail['prices'][0]['fx']);
    }

    public function test_the_lookup_endpoint_answers_with_the_rate_that_applies(): void
    {
        $this->rate('USD', '2026-11-18', '48');

        $this->getJson(route('settings.exchange-rates.lookup', ['currency' => 'USD', 'date' => '2026-11-22']))
            ->assertOk()
            ->assertJsonPath('rate.rate', '48')
            ->assertJsonPath('rate.rate_date', '2026-11-18')
            ->assertJsonPath('rate.source_title', 'CBE');
    }

    public function test_the_lookup_endpoint_answers_null_when_no_rate_applies_and_validates_its_input(): void
    {
        $this->getJson(route('settings.exchange-rates.lookup', ['currency' => 'USD', 'date' => '2026-11-22']))->assertOk()->assertJsonPath('rate', null);
        $this->getJson(route('settings.exchange-rates.lookup', ['currency' => 'EGP', 'date' => '2026-11-22']))->assertOk()->assertJsonPath('rate', null);
        $this->getJson(route('settings.exchange-rates.lookup', ['currency' => 'XXX', 'date' => '2026-11-22']))->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->getJson(route('settings.exchange-rates.lookup', ['currency' => 'USD', 'date' => 'soon']))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson(route('settings.exchange-rates.lookup'))->assertUnprocessable();
    }
}
