<?php

use App\Services\Currency\Providers\ManualExchangeRateProvider;

return [
    /*
    | The base currency: every cost is computed and stored in it. Fixed, not editable by users (the engine's `Currency`
    | list in packages/engine/src/core/quantity.ts must contain it and every supported code; a test compares them).
    */
    'base' => 'EGP',

    /*
    | Currencies a price or an exchange rate may be quoted in, keyed by ISO 4217 code, with the code's minor units
    | (decimal places of its smallest coin). Names, short names and symbols live in lang/<locale>/currencies.php.
    */
    'supported' => [
        'EGP' => ['minor_units' => 2],
        'USD' => ['minor_units' => 2],
        'EUR' => ['minor_units' => 2],
        'CNY' => ['minor_units' => 2],
        'SAR' => ['minor_units' => 2],
        'AED' => ['minor_units' => 2],
        'GBP' => ['minor_units' => 2],
    ],

    /*
    | Where exchange rates come from. Today they are typed in (`manual`). Automatic fetching (CBE, C-05) is a provider class
    | that implements App\Services\Currency\Providers\ExchangeRateProvider, listed here and selected by `provider`.
    | No rate is ever invented or defaulted.
    */
    'provider' => 'manual',
    'providers' => [
        'manual' => ManualExchangeRateProvider::class,
    ],

    /* Rows per page of the exchange rates list. */
    'list_per_page' => 25,
];
