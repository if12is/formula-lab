<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum FormulaStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case LabTesting = 'lab_testing';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
