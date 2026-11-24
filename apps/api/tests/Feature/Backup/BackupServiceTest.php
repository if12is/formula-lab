<?php

namespace Tests\Feature\Backup;

use App\Enums\Backup\BackupRunKind;
use App\Enums\Backup\BackupRunStatus;
use App\Exceptions\Domain\BackupException;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\BackupRun;
use App\Services\Backup\BackupService;
use App\Support\Clock\Clock;
use App\Support\Clock\FixedClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakeDatabaseDriver;
use Tests\Concerns\PreparesBackups;
use Tests\TestCase;

/** U-05 / U-10: the backup_runs log, the commands and the schedule. */
class BackupServiceTest extends TestCase
{
    use PreparesBackups, RefreshDatabase;

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

    public function test_a_backup_run_is_logged_with_hash_sizes_and_duration_from_the_injected_clock(): void
    {
        $this->driver->advance = $this->clock;
        $this->driver->advanceTo = CarbonImmutable::parse('2026-11-24 02:00:07.250', 'UTC');

        $result = app(BackupService::class)->run();

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRunKind::Backup, $run->kind);
        $this->assertSame(BackupRunStatus::Succeeded, $run->status);
        $this->assertSame($result['manifest']->sha256, $run->sha256);
        $this->assertSame($result['manifest']->file, $run->file_name);
        $this->assertSame($result['manifest']->encryptedBytes, $run->encrypted_bytes);
        $this->assertSame(strlen($this->driver->dumpBytes), $run->plain_bytes);
        $this->assertSame(7250, $run->duration_ms);
        $this->assertEquals(['audit_log' => 10, 'formulas' => 3], $run->detail['tables']);
        $this->assertSame('2026-11-24 02:00:00', $run->started_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_failed_backup_is_logged_and_still_raises(): void
    {
        $this->driver->failDump = true;

        $this->assertThrowsException(BackupException::class, fn () => app(BackupService::class)->run(), 'connection refused');

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRunStatus::Failed, $run->status);
        $this->assertStringContainsString('connection refused', $run->failure_reason);
        $this->assertNull($run->sha256);
    }

    public function test_a_missing_key_fails_the_run_with_the_translated_exception_and_is_logged(): void
    {
        config(['backup.encryption_key' => null]);

        $this->assertThrowsException(BackupException::class, fn () => app(BackupService::class)->run(), __('exceptions.backup_key_missing'));

        $this->assertSame(__('exceptions.backup_key_missing'), BackupRun::query()->sole()->failure_reason);
        $this->assertSame([], array_diff(scandir($this->backupDirectory), ['.', '..']));
    }

    public function test_run_prunes_old_backups_and_logs_how_many(): void
    {
        $service = app(BackupService::class);
        $this->clock->advanceTo(CarbonImmutable::parse('2026-09-01 02:00:00', 'UTC'));
        $service->run();
        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-24 02:00:00', 'UTC'));

        $this->assertSame(1, $service->run()['pruned']);
        $this->assertCount(1, glob($this->backupDirectory.'/*.flbak'));
        $this->assertSame(1, BackupRun::query()->where('kind', BackupRunKind::Backup)->orderByDesc('started_at')->first()->detail['pruned']);
    }

    public function test_verify_is_logged_as_succeeded_or_failed(): void
    {
        $service = app(BackupService::class);
        $manifest = $service->run()['manifest'];

        $service->verify();
        $this->flipByte($this->backupDirectory.'/'.$manifest->file);
        $this->assertThrowsException(BackupException::class, fn () => $service->verify($manifest->file));

        $runs = BackupRun::query()->where('kind', BackupRunKind::Verify)->orderBy('finished_at')->get();
        $this->assertCount(2, $runs);
        $this->assertEqualsCanonicalizing([BackupRunStatus::Succeeded, BackupRunStatus::Failed], $runs->pluck('status')->all());
        $this->assertSame($manifest->file, $runs->first()->file_name);
    }

    public function test_verify_with_no_backup_is_a_logged_failure(): void
    {
        $this->assertThrowsException(BackupException::class, fn () => app(BackupService::class)->verify(), __('exceptions.backup_none_found', ['directory' => $this->backupDirectory]));

        $this->assertSame(BackupRunStatus::Failed, BackupRun::query()->sole()->status);
    }

    public function test_restore_logs_the_target_and_a_count_difference_is_a_failed_run(): void
    {
        $service = app(BackupService::class);
        $service->run();

        $this->assertTrue($service->restore(null, 'scratch_ok')->allMatch());
        $this->driver->restoredCounts = ['formulas' => 0, 'audit_log' => 10];
        $this->assertFalse($service->restore(null, 'scratch_bad')->allMatch());

        $runs = BackupRun::query()->where('kind', BackupRunKind::Restore)->orderBy('target_database')->get();
        $this->assertSame(['scratch_bad', 'scratch_ok'], $runs->pluck('target_database')->all());
        $this->assertSame([BackupRunStatus::Failed, BackupRunStatus::Succeeded], $runs->pluck('status')->all());
        $this->assertSame(__('exceptions.backup_counts_differ'), $runs[0]->failure_reason);
        $this->assertSame([], $this->driver->dropped, 'a plain restore keeps the database for inspection');
    }

    public function test_a_refused_restore_into_the_live_database_is_logged_and_writes_nothing(): void
    {
        $service = app(BackupService::class);
        $service->run();
        $live = (string) config('database.connections.'.config('database.default').'.database');

        $this->assertThrowsException(BackupException::class, fn () => $service->restore(null, $live), __('exceptions.backup_live_database_guard', ['database' => $live]));

        $this->assertSame([], $this->driver->created);
        $this->assertSame(BackupRunStatus::Failed, BackupRun::query()->where('kind', BackupRunKind::Restore)->sole()->status);
    }

    public function test_the_monthly_restore_test_uses_a_throwaway_database_and_drops_it(): void
    {
        $service = app(BackupService::class);
        $service->run();

        $report = $service->restoreTest();

        $this->assertTrue($report->allMatch());
        $this->assertSame(['fl_restore_test_20261124020000'], $this->driver->created);
        $this->assertSame(['fl_restore_test_20261124020000'], $this->driver->dropped);
        $this->assertSame([], $this->driver->databases);
        $run = BackupRun::query()->where('kind', BackupRunKind::RestoreTest)->sole();
        $this->assertSame(BackupRunStatus::Succeeded, $run->status);
        $this->assertTrue($run->detail['dropped']);
    }

    public function test_the_restore_test_drops_the_database_even_when_counts_differ_but_never_one_it_did_not_create(): void
    {
        $service = app(BackupService::class);
        $service->run();

        $this->driver->restoredCounts = ['formulas' => 1, 'audit_log' => 1];
        $this->assertFalse($service->restoreTest()->allMatch());
        $this->assertSame(['fl_restore_test_20261124020000'], $this->driver->dropped);

        // The same second again with that name already present: refused, and the existing database is left alone.
        $this->driver->dropped = [];
        $this->driver->databases = ['fl_restore_test_20261124020000'];
        $this->assertThrowsException(BackupException::class, fn () => $service->restoreTest(), __('exceptions.backup_target_exists', ['database' => 'fl_restore_test_20261124020000']));
        $this->assertSame([], $this->driver->dropped);
    }

    public function test_the_restore_test_without_any_backup_is_a_logged_failure(): void
    {
        $this->assertThrowsException(BackupException::class, fn () => app(BackupService::class)->restoreTest());

        $this->assertSame(BackupRunStatus::Failed, BackupRun::query()->where('kind', BackupRunKind::RestoreTest)->sole()->status);
    }

    public function test_the_backup_log_is_append_only(): void
    {
        $run = BackupRun::factory()->create();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $run->update(['status' => BackupRunStatus::Failed]));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $run->delete());
    }

    public function test_the_commands_report_in_the_translated_language_and_set_the_exit_code(): void
    {
        $this->artisan('backup:run')->expectsOutputToContain('fl-backup-20261124T020000Z.flbak')->assertExitCode(0);
        $this->artisan('backup:verify')->expectsOutputToContain('SHA-256')->assertExitCode(0);
        $this->artisan('backup:restore', ['--into' => 'scratch_cmd'])->expectsOutputToContain('formulas')->assertExitCode(0);
        $this->artisan('backup:restore')->expectsOutputToContain(__('backup.command.into_required'))->assertExitCode(1);
        $this->artisan('backup:restore', ['--into' => 'scratch_cmd'])->expectsOutputToContain(__('exceptions.backup_target_exists', ['database' => 'scratch_cmd']))->assertExitCode(1);

        $this->driver->restoredCounts = ['formulas' => 0, 'audit_log' => 0];
        $this->artisan('backup:restore-test')->expectsOutputToContain(__('exceptions.backup_counts_differ'))->assertExitCode(1);

        config(['backup.encryption_key' => null]);
        $this->artisan('backup:run')->expectsOutputToContain(__('exceptions.backup_key_missing'))->assertExitCode(1);
    }

    public function test_the_backup_is_scheduled_daily_and_the_restore_test_monthly(): void
    {
        $events = collect(app(Schedule::class)->events());
        $daily = $events->first(fn ($event) => str_contains($event->command, 'backup:run'));
        $monthly = $events->first(fn ($event) => str_contains($event->command, 'backup:restore-test'));

        $this->assertNotNull($daily);
        $this->assertSame('0 2 * * *', $daily->expression);
        $this->assertNotNull($monthly);
        $this->assertSame('0 4 1 * *', $monthly->expression);
    }
}
