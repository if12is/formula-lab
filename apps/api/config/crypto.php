<?php

/*
| Encryption of formula composition at rest (U-02). Envelope scheme: every tenant has a random 32-byte data key; the data key is
| wrapped (XChaCha20-Poly1305, libsodium) by the master key below and only the wrapped form is stored (tenants.wrapped_data_key).
| The master key lives in the server environment, never in the database, the repository or a backup of the database.
| Generate one with `php artisan crypto:generate-master-key`; rotation procedure: plan/02-specs/composition-encryption.md.
*/
return [
    /* "base64:<32 random bytes>" (or 64 hex characters). Missing or malformed: every read or write of an encrypted column fails with a translated error, never plaintext. */
    'master_key' => env('APP_MASTER_KEY'),

    /* Comma-separated older master keys, kept only while a rotation is in progress so the old wrapped keys can still be opened and re-wrapped. */
    'previous_master_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_MASTER_KEY_PREVIOUS', ''))))),

    /*
    | Only for the window between the schema migration and `crypto:encrypt-compositions` on a database that already holds rows:
    | a stored value that is not encrypted is then read as-is instead of refused. Leave false everywhere else; writes always encrypt.
    */
    'allow_legacy_plaintext' => (bool) env('CRYPTO_ALLOW_LEGACY_PLAINTEXT', false),
];
