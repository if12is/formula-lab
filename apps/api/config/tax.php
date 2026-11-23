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

    /*
    | Legal reference read on 2026-11-23 (research round 2). Reference metadata only: it does NOT change the rates, the
    | regime confidence or the `pending` verification above, because whether a product class is taxable at 14% is not settled.
    | Law 67/2016, Article 3 (ETA English translation, updated 15 Jan 2023): the standard rate is 13% for FY2016/17 and 14%
    | from FY2017/18; exports are zero-rated. Confidence L3 for that rate; amendments after Jan 2023 were not checked.
    | The same law's Table lists "Soap and synthetic detergents for household use" (First, item 10: Table Tax 5%, no VAT line)
    | and "Perfume & moistening products, cosmetics, health and hair-care products" (Second, item 5: VAT plus Table Tax 8%).
    | Owner and accountant must decide how those apply (orchestration/HUMAN-TODO.md TAX).
    */
    'legal_reference' => [
        'law' => 'Egypt VAT Law No. 67 of 2016',
        'rate_article' => 'Article 3',
        'standard_rate_pct' => '14',
        'url' => 'https://eta.gov.eg/sites/default/files/2024-02/Law-english-no.67-2016.pdf.pdf',
        'confidence' => 'L3_standard',
        'retrieved_at' => '2026-11-23',
    ],

    /* Decimal places of the rate sent to Sanad (contract 4.2 shows "14.00"). */
    'sanad_rate_places' => 2,
];
