<?php

return [
    'list_demo' => [
        'title' => 'List building blocks',
        'description' => 'Development-only page: search, filters, sort and state kept in the URL.',
        'columns' => [
            'trade_name' => 'Material name',
            'grade' => 'Grade',
            'function' => 'Function',
            'status' => 'Status',
            'is_mixture' => 'Mixture',
            'created_at' => 'Date added',
        ],
        'filters' => [
            'is_mixture' => 'Mixtures only',
            'function' => 'Function',
        ],
        'yes' => 'Yes',
        'no' => 'No',
        'local_select' => 'Pick from a local list',
        'remote_select' => 'Pick by searching the server',
        'select_hint' => 'Type part of the name to search.',
        'selected' => 'Selected',
    ],
];
