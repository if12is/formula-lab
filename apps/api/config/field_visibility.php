<?php

/*
| Server-side field visibility (U-07). What a role may receive is decided HERE and enforced on the server by one middleware
| (App\Http\Middleware\ApplyFieldVisibility): the keys of a field group a role may not see are removed from every Inertia payload
| (first load, partial reloads, deferred props) before it leaves the server, and the screens that exist only to show such a group
| answer 403. Hiding something in the UI is never the protection.
|
| The role comes from `users.role` (the minimal carrier until U-01 brings accounts and real permissions). The four roles are the
| owner's own wording in queue item U-01 (owner, chemist, production = weights only without percentages, accountant = cost only);
| which exact groups each one gets is a default the owner confirms (orchestration/HUMAN-TODO.md, U-07). Nothing here is a chemical or
| regulatory number.
|
| Groups:
|   cost       what making the product costs us: material prices paid, formula and unit cost, conversion, landed cost.
|   price      what we sell for and what is derived from it: sell prices, price lists and ladder, margins, tax, discounts.
|   percentage the percentage composition of a formula (the recipe itself).
*/
return [
    /* Roles and the groups each may see. A role not listed here falls back to `unlisted_role_groups`. */
    'roles' => [
        'owner' => ['cost', 'price', 'percentage'],
        'chemist' => ['cost', 'price', 'percentage'],
        'production' => [],
        'accountant' => ['cost'],
    ],

    /*
    | Nobody signed in (no accounts exist before U-01) and a role the owner has not named keep the full view, exactly as the app behaved
    | before this item, so existing fixtures and the demo keep working. Set both to [] to switch to deny-by-default once U-01 is done.
    */
    'anonymous_groups' => ['cost', 'price', 'percentage'],
    'unlisted_role_groups' => ['cost', 'price', 'percentage'],

    /*
    | Key patterns per group, matched against the snake_case form of a payload key (unitCost -> unit_cost) with `*` as the wildcard.
    | A key is removed (with everything under it) when its group is not allowed. The first group that matches decides.
    */
    'keys' => [
        'cost' => [
            'cost', 'costs', 'cost_*', '*_cost', '*_costs', '*_cost_*', '*_egp', '*_egp_*', 'egp_*',
            'prices', 'material_prices', 'price_per_kg', 'landed_*', 'unit_cost_*', 'conversion_*',
        ],
        'price' => [
            'price', '*_price', 'price_*', '*_prices', 'wholesale_*', 'margin', 'margin_*', '*_margin', '*_margin_*', 'markup*',
            'ladder', '*_ladder', 'tax', 'tax_*', '*_tax', 'vat*', '*_vat', '*_vat_*', 'discount*', 'subtotal',
        ],
        'percentage' => ['percent', 'percent_*', '*_percent', 'percentages'],
    ],

    /* Keys that are never removed nor searched: the audience flags the screens read, and the translation dictionary (its keys are words such as `dashboard.kpi.approvals_caption_no_cost`, not data). */
    'exempt_keys' => ['audience', 'field_audience', 'translations'],

    /*
    | Screens that exist only to show one group: route-name patterns (`*` wildcard) answered with 403 when the role lacks the group.
    | Every other screen is served with the group's keys removed.
    */
    'routes' => [
        'cost' => ['formulas.cost-report', 'formulas.unit-cost.update', 'materials.prices.*', 'tiers.*'],
        'price' => ['price-lists.*', 'price-ladder.*', 'formulas.publish*', 'formulas.tax.update'],
        'percentage' => ['formulas.show', 'formulas.compare', 'formulas.salt-curve', 'formulas.versions.edit', 'formulas.versions.store', 'formulas.versions.label'],
    ],
];
