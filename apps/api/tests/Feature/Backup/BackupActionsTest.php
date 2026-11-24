<?php

namespace Tests\Feature\Backup;

use App\Actions\Backup\CreateBackupAction;
use App\Actions\Backup\PruneBackupsAction;
use App\Actions\Backup\RestoreBackupAction;
use App\Actions\Backup\VerifyBackupAction;
use App\Exceptions\Domain\BackupException;
use App\Support\Backup\BackupStore;
use App\Support\Clock\Clock;
use App\Support\Clock\FixedClock;
use Carbon\CarbonImmutable;
use Tests\Concerns\FakeDatabaseDriver;
use Tests\Concerns\PreparesBackups;
use Tests\TestCase;

/** U-05 / U-10: the backup file, its manifest, verification, the restore guards and retention (fake pg_dump, fake clock, temp directory). */
class BackupActionsTest extends TestCase
{
    use PreparesBackups;

    private FakeDatabaseDriver $driver;

    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = $this->prepareBackups();
        $this->clock = new FixedClock(CarbonImmutable::parse('2026-11-24 02:00:00', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->cleanBackups();
        parent::tearDown();
    }

    private function backup(): string
    {
        $manifest = app(CreateBackupAction::class)('source_db');

        return app(BackupStore::class)->pathFor($manifest->file);
    }

    public function test_a_backup_is_an_encrypted_file_with_a_sha256_manifest_next_to_it(): void
    {
        $path = $this->backup();
        $manifest = app(BackupStore::class)->readManifest($path);

        $this->assertSame('fl-backup-20261124T020000Z.flbak', basename($path));
        $this->assertSame(hash_file('sha256', $path), $manifest->sha256);
        $this->assertSame(filesize($path), $manifest->encryptedBytes);
        $this->assertSame(strlen($this->driver->dumpBytes), $manifest->plainBytes);
        $this->assertSame(['audit_log' => 10, 'formulas' => 3], $manifest->tables);
        $this->assertSame('source_db', $manifest->database);
        $this->assertFileExists($path.'.manifest.json');
        $this->assertStringNotContainsString(substr($this->driver->dumpBytes, 1000, 64), (string) file_get_contents($path));
        $this->assertSame([], glob($this->backupDirectory.'/.partial-*') ?: []);
    }

    public function test_a_failed_dump_leaves_no_file_behind(): void
    {
        $this->driver->failDump = true;

        $this->assertThrowsException(BackupException::class, fn () => $this->backup(), 'connection refused');
        $this->assertSame([], array_diff(scandir($this->backupDirectory), ['.', '..']));
    }

    public function test_verification_passes_for_an_untouched_backup(): void
    {
        $manifest = app(VerifyBackupAction::class)($this->backup());

        $this->assertSame(strlen($this->driver->dumpBytes), $manifest->plainBytes);
    }

    public function test_verification_detects_a_changed_backup_file(): void
    {
        $path = $this->backup();
        $this->flipByte($path);

        $this->assertThrowsException(BackupException::class, fn () => app(VerifyBackupAction::class)($path), __('exceptions.backup_hash_mismatch', ['file' => basename($path)]));
    }

    public function test_verification_detects_a_file_and_hash_rewritten_together_because_the_manifest_is_signed(): void
    {
        $path = $this->backup();
        $this->flipByte($path);
        $manifestPath = $path.'.manifest.json';
        $data = json_decode(file_get_contents($manifestPath), true);
        $data['manifest']['sha256'] = hash_file('sha256', $path);
        file_put_contents($manifestPath, json_encode($data));

        $this->assertThrowsException(BackupException::class, fn () => app(VerifyBackupAction::class)($path), __('exceptions.backup_manifest_invalid', ['file' => basename($path)]));
    }

    public function test_verification_detects_a_changed_row_count_in_the_manifest(): void
    {
        $path = $this->backup();
        $data = json_decode(file_get_contents($path.'.manifest.json'), true);
        $data['manifest']['tables']['formulas'] = 99;
        file_put_contents($path.'.manifest.json', json_encode($data));

        $this->assertThrowsException(BackupException::class, fn () => app(VerifyBackupAction::class)($path), __('exceptions.backup_manifest_invalid', ['file' => basename($path)]));
    }

    public function test_verification_needs_the_manifest_and_the_right_key(): void
    {
        $path = $this->backup();

        config(['backup.encryption_key' => base64_encode(str_repeat('z', 32))]);
        $this->assertThrowsException(BackupException::class, fn () => app(VerifyBackupAction::class)($path), __('exceptions.backup_manifest_invalid', ['file' => basename($path)]));

        config(['backup.encryption_key' => null]);
        $this->assertThrowsException(BackupException::class, fn () => app(VerifyBackupAction::class)($path), __('exceptions.backup_key_missing'));

        config(['backup.encryption_key' => base64_encode(str_repeat('k', 32))]);
        unlink($path.'.manifest.json');
        $this->assertThrowsException(BackupException::class, fn () => app(VerifyBackupAction::class)($path), __('exceptions.backup_manifest_missing', ['file' => basename($path)]));
    }

    public function test_a_restore_decrypts_into_a_new_database_and_reports_matching_counts(): void
    {
        $path = $this->backup();

        $report = app(RestoreBackupAction::class)($path, 'scratch_one');

        $this->assertTrue($report->allMatch());
        $this->assertSame(['scratch_one'], $this->driver->created);
        $this->assertSame([$this->driver->dumpBytes], $this->driver->restoredBytes);
        $this->assertSame([['table' => 'audit_log', 'expected' => 10, 'actual' => 10, 'matches' => true], ['table' => 'formulas', 'expected' => 3, 'actual' => 3, 'matches' => true]], $report->tables);
        $this->assertSame([], glob($this->backupDirectory.'/.restore-*') ?: [], 'the decrypted dump must not stay on disk');
    }

    public function test_a_restore_reports_a_row_count_that_differs_from_the_manifest(): void
    {
        $path = $this->backup();
        $this->driver->restoredCounts = ['formulas' => 2, 'audit_log' => 10];

        $report = app(RestoreBackupAction::class)($path, 'scratch_one');

        $this->assertFalse($report->allMatch());
        $this->assertSame(['table' => 'formulas', 'expected' => 3, 'actual' => 2, 'matches' => false], $report->tables[1]);
    }

    public function test_a_restore_never_touches_the_live_or_source_database(): void
    {
        $path = $this->backup();
        $live = (string) config('database.connections.'.config('database.default').'.database');
        $this->driver->databases = [$live];

        foreach ([$live, strtoupper($live), 'source_db'] as $name) {
            $this->assertThrowsException(BackupException::class, fn () => app(RestoreBackupAction::class)($path, $name), __('exceptions.backup_live_database_guard', ['database' => $name]));
        }

        $this->assertSame([], $this->driver->created);
        $this->assertSame([], $this->driver->dropped);
    }

    public function test_a_restore_refuses_an_existing_database_and_an_unsafe_name(): void
    {
        $path = $this->backup();
        $this->driver->databases = ['already_here'];

        $this->assertThrowsException(BackupException::class, fn () => app(RestoreBackupAction::class)($path, 'already_here'), __('exceptions.backup_target_exists', ['database' => 'already_here']));
        foreach (['bad name', 'x"; drop database y; --', '1abc', ''] as $name) {
            $this->assertThrowsException(BackupException::class, fn () => app(RestoreBackupAction::class)($path, $name), __('exceptions.backup_invalid_database_name', ['database' => $name]));
        }

        $this->assertSame([], $this->driver->created);
        $this->assertSame([], $this->driver->dropped);
    }

    public function test_a_tampered_backup_is_rejected_before_any_database_is_created(): void
    {
        $path = $this->backup();
        $this->flipByte($path);

        $this->assertThrowsException(BackupException::class, fn () => app(RestoreBackupAction::class)($path, 'scratch_one'));

        $this->assertSame([], $this->driver->created);
        $this->assertSame([], $this->driver->restoredBytes);
    }

    public function test_a_failed_load_drops_the_half_restored_database_it_created(): void
    {
        $path = $this->backup();
        $this->driver->failRestore = true;

        $this->assertThrowsException(BackupException::class, fn () => app(RestoreBackupAction::class)($path, 'scratch_one'), 'syntax error');

        $this->assertSame(['scratch_one'], $this->driver->dropped);
        $this->assertSame([], glob($this->backupDirectory.'/.restore-*') ?: []);
    }

    public function test_pruning_removes_backups_older_than_the_retention_and_keeps_the_newest(): void
    {
        $store = app(BackupStore::class);
        $names = [];
        foreach (['2026-09-01', '2026-11-01', '2026-11-20'] as $day) {
            $this->clock->advanceTo(CarbonImmutable::parse("{$day} 02:00:00", 'UTC'));
            $names[$day] = $store->pathFor(app(CreateBackupAction::class)('source_db')->file);
        }

        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-24 02:00:00', 'UTC'));
        $this->assertSame(1, app(PruneBackupsAction::class)());

        $this->assertFileDoesNotExist($names['2026-09-01']);
        $this->assertFileDoesNotExist($names['2026-09-01'].'.manifest.json');
        $this->assertFileExists($names['2026-11-01']);
        $this->assertFileExists($names['2026-11-20']);
    }

    public function test_pruning_always_keeps_the_newest_backup_even_when_it_is_old(): void
    {
        $path = $this->backup();
        $this->clock->advanceTo(CarbonImmutable::parse('2027-06-01', 'UTC'));

        $this->assertSame(0, app(PruneBackupsAction::class)());
        $this->assertFileExists($path);
    }

    public function test_pruning_is_off_with_zero_retention_and_skips_unreadable_manifests(): void
    {
        $store = app(BackupStore::class);
        $this->clock->advanceTo(CarbonImmutable::parse('2026-01-01', 'UTC'));
        $old = $store->pathFor(app(CreateBackupAction::class)('source_db')->file);
        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-20', 'UTC'));
        $this->backup();
        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-24', 'UTC'));

        config(['backup.retention_days' => 0]);
        $this->assertSame(0, app(PruneBackupsAction::class)());

        config(['backup.retention_days' => 30]);
        file_put_contents($old.'.manifest.json', 'not json');
        $this->assertSame(0, app(PruneBackupsAction::class)());
        $this->assertFileExists($old);
    }

    public function test_the_store_resolves_the_newest_backup_by_default_and_complains_when_there_is_none(): void
    {
        $store = app(BackupStore::class);
        $this->assertThrowsException(BackupException::class, fn () => $store->resolve(null), __('exceptions.backup_none_found', ['directory' => $this->backupDirectory]));

        $this->backup();
        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-25 02:00:00', 'UTC'));
        $newest = $this->backup();

        $this->assertSame($newest, $store->resolve(null));
        $this->assertSame($newest, $store->resolve(basename($newest)));
        $this->assertThrowsException(BackupException::class, fn () => $store->resolve('nope.flbak'), 'nope.flbak');
    }
}
