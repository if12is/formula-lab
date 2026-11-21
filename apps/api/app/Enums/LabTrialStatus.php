<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** A lab trial takes measurements while in progress; completing it closes the record. */
enum LabTrialStatus: string
{
    use HasOptions;

    case InProgress = 'in_progress';
    case Completed = 'completed';
}
