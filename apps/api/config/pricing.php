<?php

/*
| Customer price lists (contract section 8). A list price is computed by the engine from the product's unit cost and either
| a margin RULE from this file or an explicit override someone sourced; this file holds NO margin of its own.
|
| `rules` is empty until the owner defines them (orchestration/HUMAN-TODO.md, PRICE-LISTS): the app never ships a margin.
| Until then a line priced by a rule reports the rule as missing and nothing can be published for it. Each rule is a
| plain array, all values strings, and every part of it is required except `distribution_cost`:
|
|   'merchants-standard' => [
|       'label' => ['ar' => 'تجار الجملة', 'en' => 'Wholesale merchants'],
|       'basis' => 'markup_on_cost',            // or 'margin_on_price'
|       'margin_pct' => '<percent>',
|       'distribution_cost' => null,            // optional, EGP per unit, added after the margin
|       'source' => '<who decided it and when>', // shown next to the price; the rule's source
|       'confidence' => 'L6_community',         // L1_coa_batch .. L7_estimate
|       'valid_from' => '<YYYY-MM-DD>',         // the date the decision was taken
|   ],
|
| `PRICING_RULES` (a JSON object of such rules) is how an installation supplies them until a settings screen exists; the
| end-to-end tests use it too. A rule with a missing part is passed to the engine incomplete, which reports exactly what is
| missing; the app never fills a gap.
*/
return [
    /* The only currency a Sanad price list accepts (contract 8.2). */
    'currency' => 'EGP',

    'rules' => (array) json_decode((string) env('PRICING_RULES', '{}'), true),

    'list_per_page' => 25,

    /* Rows the customer / product selectors return per search. */
    'search_limit' => 20,

    /* Most dollar moves the price ladder screen tries at once. */
    'ladder_max_fx_changes' => 5,

    /* Decimal places of the price sent to Sanad (it rounds to the same, contract 8.2). */
    'price_places' => 2,
];
