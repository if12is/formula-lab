<?php

return [
    'command' => [
        'master_key_generated' => 'New master key (put it in the server environment; keep it out of the repository and out of database backups):',
        'dry_run' => 'Dry run: nothing was written.',
        'rewrap_summary' => 'Tenants: :tenants, re-wrapped: :rewrapped, already on the current key: :current, without a key: :without_key.',
        'rewrap_next' => 'Once every tenant is on the current key, remove APP_MASTER_KEY_PREVIOUS.',
        'encrypt_summary' => 'Versions scanned: :scanned, needing encryption: :pending, encrypted: :encrypted, columns encrypted: :columns, already encrypted: :already.',
        'encrypt_failure' => 'Failed for version :version: :reason',
        'encrypt_hash_mismatch' => 'the recorded hash does not match the version\'s content before encryption, so it was left untouched',
        'encrypt_ok' => 'Done: every version is encrypted and its hash is identical before and after.',
        'tenant_unknown' => 'There is no tenant with id :tenant.',
    ],
];
