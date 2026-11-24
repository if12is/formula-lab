<?php

namespace App\Actions\Backup;

use App\Support\Backup\BackupStore;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;

/** Deletes backups (file and manifest) older than the configured retention. The newest backup and any backup with an unreadable manifest are always kept. Returns how many were deleted. */
final class PruneBackupsAction
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BackupStore $store,
    ) {}

    public function __invoke(): int
    {
        $days = (int) config('backup.retention_days');
        if ($days <= 0) {
            return 0;
        }

        $cutoff = $this->clock->now()->subDays($days);
        $pruned = 0;

        // files() is newest first; the first one is the safety copy that stays whatever its age.
        foreach (array_slice($this->store->files(), 1) as $path) {
            $manifest = $this->store->readManifest($path);
            if ($manifest === null || CarbonImmutable::parse($manifest->createdAt)->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            @unlink($path);
            @unlink($this->store->manifestPath($path));
            $pruned++;
        }

        return $pruned;
    }
}
