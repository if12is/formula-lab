<?php

/*
| The home dashboard (S-15). Every figure on it comes from the engine over the app's own records; this file only holds what the owner decides.
|
| `stale_price_days`: a material whose newest price quote is older than this many days counts as stale. The owner has not decided it
| (orchestration/HUMAN-TODO.md, S-15), so it is empty and the "stale prices" figure says "threshold not set" instead of counting against a guess.
| Set DASHBOARD_STALE_PRICE_DAYS to a whole number of days to switch it on.
*/
return [
    'stale_price_days' => env('DASHBOARD_STALE_PRICE_DAYS') === null || env('DASHBOARD_STALE_PRICE_DAYS') === '' ? null : (string) env('DASHBOARD_STALE_PRICE_DAYS'),

    /* Rows each list on the home page shows before "see all" (the list screens hold the full set). */
    'list_limit' => 5,
];
