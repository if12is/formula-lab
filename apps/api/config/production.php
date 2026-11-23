<?php

/*
| Production (phase Q): an approved formula version made into a batch. Formula Lab only plans, weighs, records and compares;
| the stock effect (consume the materials, receive the finished good) is Sanad's, asked for through the outbox
| (orchestration/context/sanad-contract-v1.md). Nothing here is a chemical or regulatory limit: a threshold or a limit the
| owner has not decided is null, and null is never read as "within" (orchestration/HUMAN-TODO.md).
|
| Text is never written here: names, kinds and messages are lang keys (lang/{ar,en}/production.php, enums.php).
*/
return [
    /* The units a batch size can be stated in: weighing is by mass, so a volume batch is refused until a density is chosen. */
    'batch_units' => ['kg', 'g'],

    /*
    | Balance resolutions (g) a production order can be weighed at. Each weight is rounded ONCE to the chosen one by the engine
    | (core/round.ts roundMass, half-even). The default is the display step of plan/02-specs/rounding-policy.md (0.1 g).
    */
    'scale_resolutions_g' => ['0.01', '0.1', '1'],
    'default_scale_resolution_g' => '0.1',

    /*
    | A deviation (actual against planned, in %) above this needs an approval. A decimal string, null until the owner decides:
    | with null, every non-zero deviation asks for approval (the safe side) and is shown as "threshold not set".
    | PRODUCTION_DEVIATION_THRESHOLD_PCT, e.g. "5". The 5 % price re-approval decision (config/decisions.php) is a different
    | rule and is NOT reused here.
    */
    'deviation_threshold_pct' => env('PRODUCTION_DEVIATION_THRESHOLD_PCT') === '' ? null : env('PRODUCTION_DEVIATION_THRESHOLD_PCT'),

    /*
    | Roles that may approve a deviation or a production order: empty until the owner names them, so nobody can (the safe default;
    | U-01 brings real accounts). Comma separated. Who sees prices, costs and percentages is the central policy, config/field_visibility.php.
    */
    'approver_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('PRODUCTION_APPROVER_ROLES', ''))))),

    /* The properties a process check point can name: the lab measurements plus the temperature of the mass. */
    'check_properties' => ['temperature', 'ph', 'density', 'viscosity', 'cloud_point', 'foam', 'active_matter'],
    'check_units' => [
        'temperature' => ['°C'],
        'ph' => ['pH'],
        'density' => ['g/mL', 'kg/L'],
        'viscosity' => ['mPa·s'],
        'cloud_point' => ['°C'],
        'foam' => ['mm', 'mL'],
        'active_matter' => ['%w/w'],
    ],

    /* The units a deviation between two numbers can be written in (the engine only labels them; nothing is converted). */
    'deviation_units' => ['g', 'kg', 'unit', 'EGP', '%', 'pH', '°C', 'mPa·s', 'g/mL'],

    /* The unit Sanad counts each kind of line in (contract 3.1: consume[].uom, receive.uom). */
    'sanad_uom' => ['raw_material' => 'kg', 'packaging' => 'unit', 'finished_good' => 'unit'],

    /*
    | The strong bases the alkali recomputation (Q-03) recognises by the CAS number of the material's substance (an identifier, not a
    | limit). A formula line whose substance has one of these is the base; nothing is matched by name.
    */
    'alkali_cas' => ['1310-73-2' => 'NaOH', '1310-58-3' => 'KOH'],

    /* Running numbers: PO-<year>-<number> for orders, B-<yymmdd>-<number> for batches. */
    'code_prefix' => 'PO',
    'batch_prefix' => 'B',

    'max_text_length' => 255,
    'max_note_length' => 1000,
    'max_packaging_lines' => 20,
    'max_steps' => 60,
    'max_checks_per_step' => 10,
];
