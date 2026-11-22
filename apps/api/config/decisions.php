<?php

/*
| Configuration of the decision rule sets (packages/engine/data/decisions/*.json, approved by the owner on 2026-11-21).
| The rules carry no numbers of their own where a number is a business choice: they read it from the decision context,
| and the app fills the context from here.
*/
return [
    /*
    | publish-to-sanad PS-03/PS-04: a material price that moved by more than this many percent since the cost was
    | approved needs the cost to be approved again. A decimal string (never a float); owner decision 2026-11-21.
    */
    'reapproval_price_change_threshold_pct' => '5',

    /* The actor written on a decision record while nobody is signed in (no accounts yet, U-01). */
    'anonymous_actor' => 'unauthenticated',

    /*
    | Y-04: roles allowed to override a blocked decision (comma separated in DECISIONS_OVERRIDE_ROLES). Empty until the owner
    | names them (HUMAN-TODO), so nobody can override: the safe default.
    */
    'override_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('DECISIONS_OVERRIDE_ROLES', ''))))),

    /* Y-04: longest written reason. */
    'override_reason_max_length' => 1000,

    /* Y-04: the e-signature re-asks the password; this many wrong tries per minute lock it for the user. */
    'signature_max_attempts' => 5,

    /* Where the shipped decision rule sets live. */
    'rule_sets_path' => base_path('../../packages/engine/data/decisions'),
];
