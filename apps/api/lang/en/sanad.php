<?php

return [
    'unauthenticated' => 'Missing or invalid Sanad device token.',
    'ability_missing' => 'This token does not have the required ability.',
    'device_mismatch' => 'This token is bound to a different Sanad device.',
    'device_uuid_invalid' => 'X-Device-Uuid must be a valid UUID.',
    'idempotency_key_required' => 'The Idempotency-Key header is required (255 characters at most).',
    'idempotency_mismatch' => 'The idempotency key was already used with different data.',
    'request_in_flight' => 'The request is still being processed.',
    'throttled' => 'Too many requests; retry later.',
    'command' => [
        'created' => 'Sanad device ":name" created.',
        'token_notice' => 'Token (shown once, store it in Sanad now): :token',
        'tenant_unresolved' => 'Pass --tenant=<id>: the tenant could not be chosen automatically.',
        'tenant_unknown' => 'Tenant :tenant does not exist.',
        'ability_unknown' => 'Unknown ability :ability.',
        'mock_url' => 'Mock Sanad base URL: :url (token: :token)',
        'pruned' => 'Removed :count expired idempotency keys.',
    ],
    'mock' => [
        'unknown_product' => 'Product :product is unknown to the mock Sanad.',
        'insufficient_stock' => 'Insufficient stock.',
        'insufficient_stock_line' => 'Insufficient stock.',
        'order_conflict' => 'This production order was already processed with different data.',
        'not_consumed' => 'The production order was not consumed first.',
        'not_finished_good' => 'The product is not a finished good.',
        'unauthenticated' => 'Unauthorized',
        'ability_missing' => 'Insufficient permission.',
    ],
];
