<?php

/*
| The calculators (S-07, S-14): one list drives the index cards, the routes and the page each one renders.
| The key is the address (/calculators/<key>); titles and descriptions are lang keys calculators.items.<key>.*.
| `group` places the card on the index (lang calculators.groups.<group>). Card icons live with the sidebar's in
| resources/js/config/calculators.js: the kit bundles the Phosphor icons it finds written out in the frontend.
*/
return [
    'items' => [
        'saponification' => ['component' => 'Calculators/Saponification', 'group' => 'chemistry'],
        'neutralization' => ['component' => 'Calculators/Neutralization', 'group' => 'chemistry'],
        'dilution' => ['component' => 'Calculators/Dilution', 'group' => 'chemistry'],
        'hlb' => ['component' => 'Calculators/Hlb', 'group' => 'chemistry'],
        'litre-kilo' => ['component' => 'Calculators/LitreKilo', 'group' => 'conversion'],
        'percent-from-weight' => ['component' => 'Calculators/PercentFromWeight', 'group' => 'conversion'],
        'fragrance' => ['component' => 'Calculators/Fragrance', 'group' => 'fragrance'],
    ],

    'groups' => ['chemistry', 'conversion', 'fragrance'],

    /* Where the soap property maps (a published convention each, with its source and confidence) live: engine data. */
    'soap_property_maps_path' => base_path('../../packages/engine/data/soap-property-maps'),
];
