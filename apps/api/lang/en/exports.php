<?php

/* The export log (U-03): every printed or saved-as-PDF document, with who, what, when and the hash printed on it. */
return [
    'title' => 'Export log',
    'description' => 'Every document printed or saved as PDF from a print preview: who, what, when and the hash printed on it.',
    'search_placeholder' => 'Search by subject, hash, person or formula code',
    'empty_title' => 'Nothing has been printed yet',
    'empty_description' => 'A document appears here the moment it is printed from its print preview.',
    'no_user' => 'No sign-in yet',
    'no_user_hint' => 'Accounts are not connected yet, so the person is not known.',
    'open_document' => 'Open the document',
    'copy_hash' => 'Copy the hash',
    'filters' => [
        'kind' => 'Document',
        'formula' => 'Formula',
        'watermark' => 'Watermark',
        'exported_at' => 'Printed on',
    ],
    'sorts' => [
        'when' => 'When',
        'kind' => 'Document',
        'subject' => 'Subject',
        'user' => 'Person',
    ],
    'columns' => [
        'when' => 'When',
        'kind' => 'Document',
        'subject' => 'Subject',
        'user' => 'Who',
        'watermark' => 'Watermark',
        'hash' => 'Hash',
    ],
];
