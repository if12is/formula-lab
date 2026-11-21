<?php

return [
    /*
    | Until sign-in and tenants exist (U-01, U-04), formulas belong to this tenant, created on first use.
    */
    'default_tenant_name' => 'Default',

    /*
    | Reference prices entered in Formula Lab are money per kilogram in one currency. The engine
    | costs EGP per kg; other currencies need a dated exchange rate and are not entered here yet.
    */
    'price' => [
        'currency' => 'EGP',
        'per_unit' => 'kg',
    ],

    /*
    | A price typed into the formula editor (D-09) has no date or source of its own. It is stored as
    | an estimate (L7) against this shared source. The interface shows the source by its kind, not by this title.
    */
    'unverified_source' => [
        'kind' => 'unverified_entry',
        'title' => 'Unverified entry',
    ],

    /* The dataset version label sent to the engine until a reference snapshot has been published. */
    'unpublished_dataset_version' => 'unpublished',

    /* Rows returned by the typeahead endpoints (materials, substances, sources). */
    'search_limit' => 10,

    /* Rows shown on the audit tab of a material. */
    'audit_limit' => 100,

    /* Longest text accepted for the free-text fields. */
    'max_text_length' => 255,
    'max_reason_length' => 1000,
    'max_lines' => 200,
];
