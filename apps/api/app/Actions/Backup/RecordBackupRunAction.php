<?php

namespace App\Actions\Backup;

use App\Enums\Backup\BackupRunKind;
use App\Enums\Backup\BackupRunStatus;
use App\Models\BackupRun;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;

/** Appends one backup_runs row; the finish time and the duration come from the injected clock. */
final class RecordBackupRunAction
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  array<string, mixed>|null  $detail */
    public function __invoke(
        BackupRunKind $kind,
        BackupRunStatus $status,
        CarbonImmutable $startedAt,
        ?string $fileName = null,
        ?string $sha256 = null,
        ?int $encryptedBytes = null,
        ?int $plainBytes = null,
        ?string $targetDatabase = null,
        ?string $failureReason = null,
        ?array $detail = null,
    ): BackupRun {
        $finishedAt = $this->clock->now();

        return BackupRun::query()->create([
            'kind' => $kind,
            'status' => $status,
            'file_name' => $fileName,
            'sha256' => $sha256,
            'encrypted_bytes' => $encryptedBytes,
            'plain_bytes' => $plainBytes,
            'target_database' => $targetDatabase,
            'failure_reason' => $failureReason,
            'detail' => $detail,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) round($startedAt->diffInMilliseconds($finishedAt, false))),
        ]);
    }
}
