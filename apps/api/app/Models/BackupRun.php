<?php

namespace App\Models;

use App\Enums\Backup\BackupRunKind;
use App\Enums\Backup\BackupRunStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\BackupRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One backup, verification, restore or restore test: result, sizes, SHA-256 and duration. Append-only. */
class BackupRun extends Model
{
    /** @use HasFactory<BackupRunFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'kind', 'status', 'file_name', 'sha256', 'encrypted_bytes', 'plain_bytes', 'target_database', 'failure_reason', 'detail',
        'started_at', 'finished_at', 'duration_ms',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('backup_runs'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('backup_runs'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => BackupRunKind::class,
            'status' => BackupRunStatus::class,
            'detail' => 'array',
            'encrypted_bytes' => 'integer',
            'plain_bytes' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
