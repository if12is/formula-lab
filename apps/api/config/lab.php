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
        /* Active matter found by MBAS or titration, compared with the computed one (O-05). */
        'active_matter' => ['units' => ['%w/w'], 'protocol' => null],
        /* Fragrance process losses (L-04): % of the mass entering each stage; they feed the perfume cost. */
        'ageing_loss' => ['units' => ['%w/w'], 'protocol' => null],
        'chilling_loss' => ['units' => ['%w/w'], 'protocol' => null],
        'filtration_loss' => ['units' => ['%w/w'], 'protocol' => null],
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
        'active_matter' => ['min' => null, 'max' => null],
        'ageing_loss' => ['min' => null, 'max' => null],
        'chilling_loss' => ['min' => null, 'max' => null],
        'filtration_loss' => ['min' => null, 'max' => null],
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

    /*
    | "Tested on formula X" (O-07): the measurement that counts as a stability result. A completed trial holding it, on an
    | approved version, records the version's doses and required HLBs as tested values. No acceptance limit is applied
    | here: approval and completion are the owner's decision, not a threshold (orchestration/HUMAN-TODO.md).
    */
    'stability_property' => 'stability_4w',

    /* Property keys of the expected-vs-measured comparison (O-05): the lab property and the material property it is computed from. */
    /* The loss stage of the engine (processLoss) and the lab measurement that carries it (L-04). */
    'process_loss' => ['ageing' => 'ageing_loss', 'chilling' => 'chilling_loss', 'filtration' => 'filtration_loss'],

    'comparison' => ['lab_property' => 'active_matter', 'material_property' => 'active_matter'],

    /* Longest text accepted for the free-text fields. */
    'max_text_length' => 255,
    'max_note_length' => 1000,
];
