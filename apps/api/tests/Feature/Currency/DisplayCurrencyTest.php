<?php

namespace Tests\Feature\Currency;

use App\Models\ExchangeRate;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DisplayCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_egp_with_no_second_figure(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('currency.base', 'EGP')
            ->where('currency.display', 'EGP')
            ->where('currency.rate', null)
            ->has('currency.options', 7)
            ->where('currency.options.0.value', 'EGP'));
    }

    public function test_it_saves_a_display_currency_in_a_cookie_and_confirms_it(): void
    {
        $this->from('/')->put('/settings/display-currency', ['currency' => 'USD'])
            ->assertRedirect('/')
            ->assertCookie('fl_display_currency', 'USD')
            ->assertSessionHas('success', __('settings.display_currency_saved'));
    }

    public function test_it_keeps_the_cookie_for_a_year(): void
    {
        $this->from('/')->put('/settings/display-currency', ['currency' => 'EUR']);

        $cookie = collect($this->app['cookie']->getQueuedCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'fl_display_currency');

        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(time() + config('ui.cookie_minutes') * 60, $cookie->getExpiresTime(), 5);
    }

    public function test_it_rejects_an_unsupported_currency_with_a_translated_message(): void
    {
        $this->from('/')->put('/settings/display-currency', ['currency' => 'JPY'])
            ->assertSessionHasErrors(['currency' => __('validation.in', ['attribute' => __('validation.attributes.currency')])])
            ->assertCookieMissing('fl_display_currency');

        $this->from('/')->put('/settings/display-currency', [])
            ->assertSessionHasErrors(['currency' => __('validation.required', ['attribute' => __('validation.attributes.currency')])]);
    }

    public function test_it_shares_the_display_currency_with_the_newest_rate_that_is_not_in_the_future(): void
    {
        Carbon::setTestNow('2026-11-21 09:00:00');
        $cbe = Source::factory()->create(['title' => 'CBE']);
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '48', 'rate_date' => '2026-11-18', 'source_id' => $cbe->id]);
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '49.25', 'rate_date' => '2026-11-20', 'source_id' => $cbe->id]);
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '60', 'rate_date' => '2026-11-30', 'source_id' => $cbe->id]);

        $this->withCookie('fl_display_currency', 'USD')->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('currency.display', 'USD')
            ->where('currency.rate.rate', '49.25')
            ->where('currency.rate.rate_date', '2026-11-20')
            ->where('currency.rate.source_title', 'CBE')
            ->where('currency.rate.confidence', 'L4_regulatory_db'));

        Carbon::setTestNow();
    }

    public function test_without_a_rate_the_display_currency_has_none_rather_than_a_guess(): void
    {
        $this->withCookie('fl_display_currency', 'EUR')->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('currency.display', 'EUR')
            ->where('currency.rate', null));
    }

    public function test_it_ignores_a_tampered_cookie(): void
    {
        $this->withCookie('fl_display_currency', 'DOGE')->get('/')->assertInertia(fn (Assert $page) => $page->where('currency.display', 'EGP'));
    }

    public function test_the_currency_names_travel_to_the_frontend_in_the_active_language(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('translations', fn ($translations) => $translations['currencies.USD.name'] === 'دولار أمريكي'
                && $translations['currencies.EGP.symbol'] === 'ج.م'
                && $translations['exchange_rates.title'] === 'أسعار الصرف'));

        $this->withCookie('fl_locale', 'en')->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('translations', fn ($translations) => $translations['currencies.USD.name'] === 'US dollar'));
    }
}
