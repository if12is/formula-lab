<?php

/*
| The EDA notification file assistant (N-08). What a file must contain is the engine's data (packages/engine/data/eda-checklist), not config.
*/
return [
    /* Another checklist data file for the engine to read instead of the shipped one (tests only); null = the shipped file. */
    'definitions_path' => env('EDA_CHECKLIST_DEFINITIONS_PATH'),

    /* Where an attached document is kept (a private disk: a download goes through the app) and which files are accepted. These are app limits, not regulatory ones. */
    'disk' => 'local',
    'directory' => 'eda-checklist',
    'file' => [
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'max_kb' => 10240,
    ],
];
