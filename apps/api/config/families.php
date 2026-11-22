<?php

/*
| The product family registry and the formula template library (phase K). Both are data files shipped with the engine
| (packages/engine/data), validated by the engine itself and loaded into the database by ProductFamilySeeder. The files
| are the source of truth: a regulator, IFRA category or tax regime the sources do not state stays null in them
| (orchestration/HUMAN-TODO.md) and nothing in PHP fills it in.
*/
return [
    'registry_path' => base_path('../../packages/engine/data/product-families.json'),

    'templates_glob' => base_path('../../packages/engine/data/formula-templates/*.json'),

    /*
    | Where the sources of the data files are stored: a `reference` source (a document, not a regulation), created once.
    */
    'source_kind' => 'reference',
];
