<?php

/*
| The lab loop (phase O): what a trial can measure, which measurements a formula version needs before it can be
| approved, and the simple test protocols. Nothing here is a regulatory or chemical limit: acceptance limits stay
| null until the owner supplies them (orchestration/HUMAN-TODO.md), and a null limit never counts as a pass.
|
| Text is never written here. Names, instruments and protocol steps are lang keys (lang/{ar,en}/lab.php).
*/
return [
    /* Units a trial's batch size can be stated in. */
    'batch_units' => ['g', 'kg', 'mL', 'L'],

    /*
    | The measurements a trial can record: the units each may be stored in, and the protocol template that describes
    | how it is measured (null when no template exists yet). The keys are App\Enums\LabProperty values.
    */
    'measurements' => [
        'ph' => ['units' => ['pH'], 'protocol' => 'ph'],
        'density' => ['units' => ['g/mL', 'kg/L'], 'protocol' => 'density_pycnometer'],
        'viscosity' => ['units' => ['mPa·s'], 'protocol' => 'viscosity_brookfield'],
        'cloud_point' => ['units' => ['°C'], 'protocol' => null],
        'foam' => ['units' => ['mm', 'mL'], 'protocol' => null],
        'stability_4w' => ['units' => ['%'], 'protocol' => null],
    ],

    /*
    | The measurements a completed trial must hold before a version can be approved (O-02). `by_product_type` replaces
    | the default for a formula whose product_type is a key here; an empty list there still demands a completed trial.
    | The default is a working assumption until the owner sets the set per product family.
    */
    'required_measurements' => [
        'default' => ['ph', 'density', 'viscosity'],
        'by_product_type' => [],
    ],

    /*
    | Acceptance limits per measurement, as decimal strings. null = no limit defined: the value is shown as
    | "not evaluated", never as in spec. The owner supplies real limits (HUMAN-TODO), nothing is invented here.
    */
    'acceptance_limits' => [
        'ph' => ['min' => null, 'max' => null],
        'density' => ['min' => null, 'max' => null],
        'viscosity' => ['min' => null, 'max' => null],
        'cloud_point' => ['min' => null, 'max' => null],
        'foam' => ['min' => null, 'max' => null],
        'stability_4w' => ['min' => null, 'max' => null],
    ],

    /*
    | Test protocol templates (O-06), seeded into lab_protocols by LabProtocolSeeder. `instrument` is the kind of
    | instrument the protocol needs and each step is a lang key under lab.protocols.<code>.steps. There are no numeric
    | acceptance limits and no fixed settings: the method the lab follows supplies them.
    */
    'protocols' => [
        'ph' => [
            'property' => 'ph',
            'unit' => 'pH',
            'instrument' => 'ph_meter',
            'steps' => ['calibrate', 'prepare_sample', 'measure', 'record'],
        ],
        'density_pycnometer' => [
            'property' => 'density',
            'unit' => 'g/mL',
            'instrument' => 'pycnometer',
            'steps' => ['weigh_empty', 'fill', 'weigh_filled', 'calculate', 'record'],
        ],
        'viscosity_brookfield' => [
            'property' => 'viscosity',
            'unit' => 'mPa·s',
            'instrument' => 'brookfield_viscometer',
            'steps' => ['prepare_sample', 'select_spindle', 'measure', 'record'],
        ],
    ],

    /* Longest text accepted for the free-text fields. */
    'max_text_length' => 255,
    'max_note_length' => 1000,
];
