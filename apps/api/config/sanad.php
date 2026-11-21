<?php

/*
| Sanad link (contract: orchestration/context/sanad-contract-v1.md).
| Two directions: Sanad pushes to / pulls from the inbound routes (api/sanad/v1/*), and
| in LAN direct mode Formula Lab calls Sanad Desktop (api/v1/manufacturing/*) with SanadClient.
*/
return [
    /* LAN direct mode: SanadClient. base_url has no trailing path, e.g. http://192.168.1.20:8000 */
    'client' => [
        'base_url' => env('SANAD_BASE_URL'),
        'token' => env('SANAD_TOKEN'),
        'timeout_seconds' => 30,
        // Contract section 7: max 8 tries, backoff 1s, 2s, 4s ... capped at 60s.
        'max_attempts' => 8,
        'backoff_base_seconds' => 1,
        'backoff_cap_seconds' => 60,
        'retry_statuses' => [429, 503],
        // Upper bound of pages one pull call reads before handing the cursor back.
        'max_pages_per_pull' => 50,
    ],

    /* Routes hosted by Formula Lab for the Sanad-initiated link. */
    'inbound' => [
        'max_items_per_push' => 200,
        'requests_per_minute' => 120,
        'device_token_prefix' => 'fls_',
        'device_token_length' => 48,
    ],

    'outbox' => [
        'page_size' => 100,
        // Contract 3.2: FL stops re-serving an item after this many failed acks and raises an alert.
        'max_failures' => 5,
        'id_prefix' => 'ob_',
    ],

    'idempotency' => [
        // Contract section 6: keys are kept at least 7 days.
        'retention_days' => 7,
        'max_key_length' => 255,
        // A claimed key with no stored response older than this is treated as an abandoned run.
        'in_flight_timeout_seconds' => 120,
    ],

    /* Item kinds cached by Formula Lab; point-of-sale merchandise is not its business. */
    'cached_item_kinds' => ['raw_material', 'packaging', 'finished_good'],

    /* Local/testing mock of Sanad (G-10), served under /__mock/sanad/api/v1/manufacturing/*. */
    'mock' => [
        'token' => env('SANAD_MOCK_TOKEN', 'sanad-mock-token'),
        'read_only_token' => env('SANAD_MOCK_READ_TOKEN', 'sanad-mock-read-token'),
        'fixtures_path' => base_path('tests/fakes/sanad/fixtures'),
        'page_size' => 100,
        'serve_port' => 8765,
    ],
];
