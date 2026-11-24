<?php

namespace App\Services\Backup;

use App\Actions\Backup\CreateBackupAction;
use App\Actions\Backup\PruneBackupsAction;
use App\Actions\Backup\RecordBackupRunAction;
use App\Actions\Backup\RestoreBackupAction;
use App\Actions\Backup\VerifyBackupAction;
use App\Enums\Backup\BackupRunKind;
use App\Enums\Backup\BackupRunStatus;
use App\Exceptions\Domain\BackupException;
use App\Support\Backup\BackupStore;
use App\Support\Backup\DatabaseDriver;
use App\Support\Clock\Clock;
use App\Support\DTOs\BackupManifest;
use App\Support\DTOs\RestoreReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Orchestrates backup, verify, restore and the monthly restore test, and appends one backup_runs row for each attempt, failed ones included. */
final class BackupService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BackupStore $store,
        private readonly DatabaseDriver $driver,
        private readonly CreateBackupAction $create,
        private readonly VerifyBackupAction $verify,
        private readonly RestoreBackupAction $restore,
        private readonly PruneBackupsAction $prune,
        private readonly RecordBackupRunAction $record,
    ) {}

    /**
     * @return array{manifest: BackupManifest, pruned: int}
     *
     * @throws BackupException
     */
    public function run(): array
    {
        $startedAt = $this->clock->now();

        try {
            $manifest = ($this->create)();
            $pruned = ($this->prune)();
        } catch (Throwable $exception) {
            $this->recordFailure(BackupRunKind::Backup, $startedAt, $exception);
            throw $exception;
        }

        ($this->record)(
            BackupRunKind::Backup, BackupRunStatus::Succeeded, $startedAt, $manifest->file, $manifest->sha256,
            $manifest->encryptedBytes, $manifest->plainBytes, null, null, ['tables' => $manifest->tables, 'pruned' => $pruned],
        );

        return ['manifest' => $manifest, 'pruned' => $pruned];
    }

    /** @throws BackupException */
    public function verify(?string $reference = null): BackupManifest
    {
        $startedAt = $this->clock->now();
        $fileName = $reference;

        try {
            $path = $this->store->resolve($reference);
            $fileName = basename($path);
            $manifest = ($this->verify)($path);
        } catch (Throwable $exception) {
            $this->recordFailure(BackupRunKind::Verify, $startedAt, $exception, $fileName);
            throw $exception;
        }

        ($this->record)(BackupRunKind::Verify, BackupRunStatus::Succeeded, $startedAt, $manifest->file, $manifest->sha256, $manifest->encryptedBytes, $manifest->plainBytes);

        return $manifest;
    }

    /**
     * Trial restore into a new database that stays for inspection. A count that differs from the manifest is a failed run.
     *
     * @throws BackupException
     */
    public function restore(?string $reference, string $into): RestoreReport
    {
        return $this->restoreAs(BackupRunKind::Restore, $reference, $into, dropAfter: false);
    }

    /** The monthly test: restore the newest backup into a throwaway database and drop it again, whatever the result. @throws BackupException */
    public function restoreTest(): RestoreReport
    {
        $into = (string) config('backup.scratch_prefix').$this->clock->now()->format('YmdHis');

        return $this->restoreAs(BackupRunKind::RestoreTest, null, $into, dropAfter: true);
    }

    private function restoreAs(BackupRunKind $kind, ?string $reference, string $into, bool $dropAfter): RestoreReport
    {
        $startedAt = $this->clock->now();
        $fileName = $reference;

        try {
            $path = $this->store->resolve($reference);
            $fileName = basename($path);
            $report = ($this->restore)($path, $into);
        } catch (Throwable $exception) {
            $this->recordFailure($kind, $startedAt, $exception, $fileName, $into);
            throw $exception;
        }

        // Only a restore that got as far as creating $into returns a report, so only then does this database belong to us to drop.
        if ($dropAfter) {
            $this->driver->dropDatabase($into);
        }

        $manifest = $this->store->readManifest($path);
        ($this->record)(
            $kind,
            $report->allMatch() ? BackupRunStatus::Succeeded : BackupRunStatus::Failed,
            $startedAt, $report->file, $manifest?->sha256, $manifest?->encryptedBytes, $manifest?->plainBytes, $into,
            $report->allMatch() ? null : __('exceptions.backup_counts_differ'),
            ['tables' => $report->tables, 'dropped' => $dropAfter],
        );

        return $report;
    }

    private function recordFailure(BackupRunKind $kind, CarbonImmutable $startedAt, Throwable $exception, ?string $fileName = null, ?string $target = null): void
    {
        Log::error('backup.'.$kind->value.'.failed', ['file' => $fileName, 'target' => $target, 'error' => $exception->getMessage()]);

        try {
            ($this->record)($kind, BackupRunStatus::Failed, $startedAt, $fileName, null, null, null, $target, $exception->getMessage());
        } catch (Throwable $recordError) {
            // The database being down is a reason for the backup to fail; it must not hide the original error.
            Log::error('backup.run_not_recorded', ['error' => $recordError->getMessage()]);
        }
    }
}
