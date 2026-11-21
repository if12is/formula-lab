<?php

/*
| Tax per product (owner decision 2026-11-21). A product's tax is switched off ("disabled": no tax line at all), or it
| has a rate. The rates a product may choose are listed here, as decimal strings, and nowhere else: components and
| requests read this file (through the Inertia props and the form request), never a literal.
|
| Each rate is backed by a dated regime row (tax_regimes) with a source and a confidence level; the engine checks the
| chosen rate against the regime in force on the price date, and a rate with no regime in force stops the calculation.
*/
return [
    /* The tax type key the engine is asked for (the app has one tax today). */
    'type' => 'vat',

    /* Rates a product may choose, percent as decimal strings. A rate not listed cannot be saved. */
    'allowed_rates' => ['0', '14'],

    /*
    | The regime rows seeded for those rates (TaxRegimeSeeder). `valid_from` is the date the owner's decision was
    | recorded, NOT a legal start date: prices dated earlier report the regime as missing. The legal source of the
    | rate is still to be attached (orchestration/HUMAN-TODO.md), so the regime stays `pending` until then.
    */
    'regime' => [
        'valid_from' => '2026-11-21',
        'confidence' => 'L6_community',
        'verification' => 'pending',
    ],

    /* The source record the seeded regimes cite. */
    'source' => [
        'kind' => 'owner_decision',
        'title' => 'Owner decision 2026-11-21: tax per product (disabled, 0% or 14%)',
        'retrieved_at' => '2026-11-21',
    ],

    /* Decimal places of the rate sent to Sanad (contract 4.2 shows "14.00"). */
    'sanad_rate_places' => 2,
];
