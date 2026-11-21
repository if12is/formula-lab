<?php

namespace Tests\Feature\Currency;

use App\Support\Currencies;
use Tests\TestCase;

class CurrencyRegistryTest extends TestCase
{
    public function test_the_base_currency_is_egp_and_is_supported(): void
    {
        $this->assertSame('EGP', Currencies::base());
        $this->assertTrue(Currencies::isBase('EGP'));
        $this->assertTrue(Currencies::isSupported('EGP'));
        $this->assertFalse(Currencies::isBase('USD'));
        $this->assertSame('EGP', Currencies::codes()[0]);
    }

    public function test_it_supports_the_starting_list_of_currencies(): void
    {
        $this->assertSame(['EGP', 'USD', 'EUR', 'CNY', 'SAR', 'AED', 'GBP'], Currencies::codes());
        $this->assertSame(['USD', 'EUR', 'CNY', 'SAR', 'AED', 'GBP'], Currencies::foreignCodes());
        $this->assertFalse(Currencies::isSupported('JPY'));
        $this->assertFalse(Currencies::isSupported('usd'));
    }

    public function test_every_currency_has_its_minor_units(): void
    {
        foreach (Currencies::codes() as $code) {
            $this->assertSame(2, Currencies::minorUnits($code), $code);
        }
    }

    public function test_every_currency_has_a_name_short_name_and_symbol_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (Currencies::codes() as $code) {
                foreach (['name', 'short', 'symbol'] as $field) {
                    $key = "currencies.{$code}.{$field}";
                    $this->assertNotSame($key, __($key), "missing [{$key}] in {$locale}");
                    $this->assertNotSame('', __($key));
                }
            }
        }
    }

    public function test_labels_follow_the_active_language_and_name_the_code(): void
    {
        $this->assertSame('دولار أمريكي (USD)', Currencies::label('USD'));
        $this->assertSame('ج.م', Currencies::symbol('EGP'));
        $this->assertSame('جنيه', Currencies::short('EGP'));

        app()->setLocale('en');

        $this->assertSame('US dollar (USD)', Currencies::label('USD'));
    }

    public function test_options_list_every_currency_or_only_the_foreign_ones(): void
    {
        $all = Currencies::options();
        $foreign = Currencies::options(false);

        $this->assertSame(Currencies::codes(), array_column($all, 'value'));
        $this->assertSame(Currencies::foreignCodes(), array_column($foreign, 'value'));
        $this->assertSame(['value' => 'USD', 'label' => 'دولار أمريكي (USD)', 'short' => 'دولار', 'symbol' => '$'], $foreign[0]);
    }

    public function test_an_unknown_currency_falls_back_to_its_code_instead_of_a_missing_key(): void
    {
        $this->assertSame('JPY', Currencies::name('JPY'));
    }

    public function test_the_engine_knows_exactly_the_supported_currencies(): void
    {
        $source = (string) file_get_contents(base_path('../../packages/engine/src/core/quantity.ts'));

        $this->assertSame(1, preg_match('/export const CURRENCIES = \\[([^\\]]+)\\] as const;/', $source, $match), 'engine CURRENCIES list not found');
        preg_match_all("/'([A-Z]{3})'/", $match[1], $codes);

        $this->assertSame(Currencies::codes(), $codes[1], 'config/currencies.php and the engine Currency list must match');
    }
}
