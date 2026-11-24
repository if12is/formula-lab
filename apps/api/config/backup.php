<?php

/*
| U-05 / U-10: encrypted PostgreSQL backups, their SHA-256 manifest and the trial restore. See orchestration/context/backup-runbook.md.
| Every value here is an operating choice of the owner, not a regulatory number (see HUMAN-TODO U-05/U-10).
*/
return [
    /* Directory on the local disk that holds the encrypted backups and their manifests. S3 or another disk is a later, separate step. */
    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    /* 32 random bytes, base64 encoded (a "base64:" prefix is accepted). Never committed; absent => backups refuse to run. */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    /* Days a backup is kept before `backup:run` prunes it. 0 keeps every backup. The newest backup is never pruned. */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /* Tables whose row counts are written into each manifest and compared after a restore. A name that does not exist is skipped. */
    'key_tables' => [
        'formulas',
        'formula_versions',
        'formula_lines',
        'trade_materials',
        'prices',
        'calc_runs',
        'lab_measurements',
        'production_orders',
        'production_batches',
        'audit_log',
        'users',
    ],

    /* Restore tests and `backup:restore` write only into a database that does not exist yet; this prefix names the monthly throwaway one. */
    'scratch_prefix' => env('BACKUP_SCRATCH_PREFIX', 'fl_restore_test_'),

    /* Database the tool connects to for CREATE/DROP DATABASE. */
    'maintenance_database' => env('BACKUP_MAINTENANCE_DATABASE', 'postgres'),

    'pg_dump_binary' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore_binary' => env('BACKUP_PG_RESTORE_BINARY', 'pg_restore'),

    /* Seconds a pg_dump / pg_restore process may run before it is stopped. */
    'process_timeout_seconds' => (int) env('BACKUP_PROCESS_TIMEOUT', 3600),

    'schedule' => [
        'daily_at' => env('BACKUP_DAILY_AT', '02:00'),
        'restore_test_day' => (int) env('BACKUP_RESTORE_TEST_DAY', 1),
        'restore_test_at' => env('BACKUP_RESTORE_TEST_AT', '04:00'),
    ],
];
