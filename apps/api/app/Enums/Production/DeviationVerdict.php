<?php

namespace App\Enums\Production;

use App\Enums\Concerns\HasOptions;

/** The engine's verdict on a deviation (evaluateDeviation), plus "not evaluated" for a record without two numbers to compare. */
enum DeviationVerdict: string
{
    use HasOptions;

    case None = 'none';
    case Within = 'within';
    case Exceeds = 'exceeds';
    case ThresholdNotSet = 'threshold_not_set';
    case NotEvaluated = 'not_evaluated';
}
