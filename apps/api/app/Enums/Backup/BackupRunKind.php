<?php

namespace App\Enums\Backup;

use App\Enums\Concerns\HasOptions;

/** What a backup_runs row records. */
enum BackupRunKind: string
{
    use HasOptions;

    case Backup = 'backup';
    case Verify = 'verify';
    case Restore = 'restore';
    case RestoreTest = 'restore_test';
}
