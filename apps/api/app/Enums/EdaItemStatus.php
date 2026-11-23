<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** The state of one document of an EDA notification file (N-08). An item nobody recorded is `Missing`. */
enum EdaItemStatus: string
{
    use HasOptions;

    case Missing = 'missing';
    case Attached = 'attached';
    case NotApplicable = 'not_applicable';
}
