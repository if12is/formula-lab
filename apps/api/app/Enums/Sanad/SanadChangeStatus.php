<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Outcome of applying one pushed item. */
enum SanadChangeStatus: string
{
    use HasOptions;

    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
}
