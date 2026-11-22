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

    /* Where the shipped decision rule sets live. */
    'rule_sets_path' => base_path('../../packages/engine/data/decisions'),
];
