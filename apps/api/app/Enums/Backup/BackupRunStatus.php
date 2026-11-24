<?php

namespace App\Enums\Backup;

use App\Enums\Concerns\HasOptions;

enum BackupRunStatus: string
{
    use HasOptions;

    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
