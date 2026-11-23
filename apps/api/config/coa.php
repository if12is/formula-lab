<?php

/*
| COA extraction (P-05): Claude Haiku reads a certificate of analysis and proposes values; a person confirms every value before it is saved
| (and only then it is L1). Nothing here is a chemistry or regulatory number: these are app limits and spelling aliases.
*/
return [
    /* Where an uploaded certificate is kept (a private disk: a download goes through the app) and which files are accepted. App limits, not regulatory ones. */
    'disk' => 'local',
    'directory' => 'coa',
    'file' => [
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'max_kb' => (int) env('COA_MAX_FILE_KB', 10240),
    ],

    /* Most fields one certificate may yield, and the longest answer the model may write. */
    'max_fields' => 100,
    'max_output_tokens' => (int) env('COA_MAX_OUTPUT_TOKENS', 4096),

    /*
    | The text layer of a PDF read by the server (pdftotext), used to check every number the model returns. A scan or an image has no text
    | layer: its values stay unverified and the reviewer must compare them with the original.
    */
    'text_reader' => [
        'binary' => env('COA_PDFTOTEXT_BINARY', 'pdftotext'),
        'timeout_seconds' => 30,
        'max_chars' => 200000,
    ],

    /* Property keys a certificate of analysis never sets (an IFRA limit comes from the IFRA certificate, N-xx / L-01). */
    'excluded_properties' => ['ifra_limit'],

    /* Spelling of a unit as printed (lower-cased, spaces removed) => the unit spelling the property registry stores. Spelling only: never a conversion factor. */
    'unit_aliases' => [
        'g/ml' => 'g/mL',
        'g/cm3' => 'g/mL',
        'g/cm³' => 'g/mL',
        'kg/l' => 'kg/L',
        '%w/w' => '%w/w',
        '%(w/w)' => '%w/w',
        '%m/m' => '%w/w',
        '%v/v' => '%v/v',
        '%(v/v)' => '%v/v',
        'mgkoh/g' => 'mgKOH/g',
        'gnaoh/g' => 'gNaOH/g',
        'mpa.s' => 'mPa·s',
        'mpa·s' => 'mPa·s',
        'mpas' => 'mPa·s',
        'cp' => 'mPa·s',
        'ph' => 'pH',
        'hlb' => 'HLB',
        '°c' => '°C',
    ],
];
