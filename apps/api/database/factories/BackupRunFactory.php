<?php

namespace Database\Factories;

use App\Enums\Backup\BackupRunKind;
use App\Enums\Backup\BackupRunStatus;
use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BackupRun> */
class BackupRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => BackupRunKind::Backup,
            'status' => BackupRunStatus::Succeeded,
            'file_name' => 'fl-backup-'.fake()->unique()->numerify('########').'T020000Z.flbak',
            'sha256' => hash('sha256', fake()->unique()->uuid()),
            'encrypted_bytes' => 1024,
            'plain_bytes' => 900,
            'target_database' => null,
            'failure_reason' => null,
            'detail' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 0,
        ];
    }
}
