<?php

/*
| The packaging BOM editor (S-12). Nothing here is a packaging or regulatory number: these are app limits for the pack image.
*/
return [
    /* Where a pack image is kept (a private disk: it is shown through the app, never by a public URL) and which files are accepted. SVG is left out on purpose: it can carry script. */
    'image' => [
        'disk' => 'local',
        'directory' => 'packaging/bom-images',
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_kb' => (int) env('PACKAGING_IMAGE_MAX_KB', 5120),
    ],
];
