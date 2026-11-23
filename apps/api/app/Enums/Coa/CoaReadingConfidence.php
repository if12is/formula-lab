<?php

namespace App\Enums\Coa;

use App\Enums\Concerns\HasOptions;

/** How sure the model says it is that it read a field right. It is a hint to the reviewer, never a data confidence level (L1-L7). */
enum CoaReadingConfidence: string
{
    use HasOptions;

    /** The labels live in lang/{ar,en}/coa.php (`coa.enums.reading_confidence.<value>`), with the rest of the COA screen's text. */
    public function label(): string
    {
        return __('coa.enums.reading_confidence.'.$this->value);
    }

    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
