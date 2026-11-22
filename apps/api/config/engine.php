<?php

/*
| Server-side calls into the calculation engine (packages/engine), made through App\Support\Engine\EngineRunner:
| a Node process runs one TypeScript script with JSON on stdin and JSON on stdout. The engine stays the only place
| a regulatory or numeric rule is decided; PHP never re-implements it.
*/
return [
    /* The node executable (22.7 or newer: the scripts run as TypeScript without a build step). */
    'node_binary' => env('ENGINE_NODE_BINARY', 'node'),

    /* Seconds a script may run before it is stopped. */
    'timeout' => (int) env('ENGINE_TIMEOUT', 20),

    /* The engine package, where the scripts below and the resolver hook live. */
    'package_path' => env('ENGINE_PACKAGE_PATH', base_path('../../packages/engine')),

    /*
    | Flags before the script: transform-types (the engine uses constructor parameter properties, which strip-types
    | refuses), no warnings (stderr then only holds real errors) and the hook that resolves the engine's extensionless imports.
    */
    'node_flags' => ['--experimental-transform-types', '--no-warnings', '--import', './scripts/node-ts/register.mjs'],

    /* The scripts that may be run, by name, relative to package_path. */
    'scripts' => [
        'validate-rule-set' => 'scripts/validate-rule-set.ts',
        'tested-values' => 'scripts/tested-values.ts',
        'price-list-lines' => 'scripts/price-list-lines.ts',
        'cost-report' => 'scripts/cost-report.ts',
        'publish-decision' => 'scripts/publish-decision.ts',
        'validate-families' => 'scripts/validate-families.ts',
        'check-template' => 'scripts/check-template.ts',
    ],
];
