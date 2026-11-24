# Backup runbook (U-05 / U-10)

For the owner or whoever operates the server. All commands run from `apps/api`.

## What runs by itself
| When | Command | What |
| --- | --- | --- |
| daily 02:00 (`BACKUP_DAILY_AT`) | `backup:run` | `pg_dump` (custom format, one consistent snapshot) streamed through libsodium secretstream (XChaCha20-Poly1305) into `fl-backup-<UTC time>.flbak`, plus `<file>.manifest.json`; then prunes backups older than `BACKUP_RETENTION_DAYS` (the newest backup is never pruned) |
| 1st of the month 04:00 (`BACKUP_RESTORE_TEST_DAY/AT`) | `backup:restore-test` | restores the newest backup into a throwaway database `fl_restore_test_<time>`, compares row counts with the manifest, drops it |

The Laravel scheduler must be running (`* * * * * php artisan schedule:run` in cron). Every attempt, failed ones included, is appended to `backup_runs` (append-only): kind, status, file, SHA-256, sizes, duration, reason. **Check it**: a failed or missing row is the alarm; nothing else sends one yet.

## One-time setup
1. Generate the key: `php -r 'echo base64_encode(random_bytes(32));'` and put it in `.env` as `BACKUP_ENCRYPTION_KEY`.
2. **Keep a copy of that key somewhere else** (password manager, printed in a safe). Without it every backup is unreadable. It is never committed. Keep it separate from `APP_MASTER_KEY` (U-02), and keep a copy of `APP_MASTER_KEY` too: a restored database holds the formula composition encrypted with it.
3. Optional: `BACKUP_PATH` (default `storage/app/backups`). Put it on a different disk than the database. The PostgreSQL role needs CREATEDB (for the restore test); `pg_dump` and `pg_restore` must be on the PATH (or set `BACKUP_PG_DUMP_BINARY` / `BACKUP_PG_RESTORE_BINARY`).
4. Copy the backup directory off the server regularly (S3 or another machine is not built yet; see HUMAN-TODO). A backup on the same machine does not survive losing the machine.

## Check a backup
`php artisan backup:verify [file]` (default newest): manifest signature (HMAC with the key), SHA-256 of the file against it, then a full authenticated decryption. A changed byte, a cut file, a wrong key or a missing manifest each fail with a clear message.

## Restore (trial first, always)
`php artisan backup:restore [file] --into=<new_scratch_db>`
- Verifies and decrypts first; creates the new database only afterwards, loads it all-or-nothing, prints key-table counts next to the manifest. Exit code 1 if any count differs.
- Refuses: the live database, the database the backup came from, any configured connection database, a database that already exists, an unsafe name. It never writes into an existing database.
- The scratch database stays for you to inspect (`psql <scratch_db>`); drop it when done: `DROP DATABASE <scratch_db>;`.

## Putting a restored database live (manual, after the trial passed)
The commands deliberately do not overwrite the live database. After a trial restore looks right:
1. Stop the app (maintenance mode) and the scheduler.
2. Rename the broken database aside and the scratch one into its place, in `psql` on the `postgres` database: `ALTER DATABASE formula_lab RENAME TO formula_lab_broken;` then `ALTER DATABASE <scratch_db> RENAME TO formula_lab;` (disconnect sessions first).
3. Start the app, open a few formulas, check the latest `audit_log` entries. Keep `formula_lab_broken` until you are sure.

## When something fails
- `BACKUP_ENCRYPTION_KEY` missing/invalid: set it (see above); the failed run is already in `backup_runs`.
- `pg_dump failed: ...`: the message carries the tool's own error (credentials, version mismatch, server down). Use a `pg_dump` at least as new as the server.
- Counts differ after a restore: a real problem, do not rely on that backup; run `backup:run` again and test again.
