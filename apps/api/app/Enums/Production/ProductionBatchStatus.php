<?php

namespace App\Enums\Production;

use App\Enums\Concerns\HasOptions;

enum ProductionBatchStatus: string
{
    use HasOptions;

    case InProgress = 'in_progress';
    case Completed = 'completed';
}
