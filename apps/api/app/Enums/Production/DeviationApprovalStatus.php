<?php

namespace App\Enums\Production;

use App\Enums\Concerns\HasOptions;

enum DeviationApprovalStatus: string
{
    use HasOptions;

    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
