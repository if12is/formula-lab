<?php

namespace App\Enums\Production;

use App\Enums\Concerns\HasOptions;

/** What one manufacturing step does (Q-10). */
enum ProcessStepKind: string
{
    use HasOptions;

    case Heat = 'heat';
    case Cool = 'cool';
    case Add = 'add';
    case Mix = 'mix';
    case Homogenize = 'homogenize';
    case Hold = 'hold';
    case Adjust = 'adjust';
    case Check = 'check';
}
