<?php

namespace App\Enums\Coa;

use App\Enums\Concerns\HasOptions;

/** Where a COA extraction stands. Only `extracted` is open: `confirmed` and `rejected` are final, and the status is the latest event (append-only). */
enum CoaExtractionStatus: string
{
    use HasOptions;

    /** The labels live in lang/{ar,en}/coa.php (`coa.enums.status.<value>`), with the rest of the COA screen's text. */
    public function label(): string
    {
        return __('coa.enums.status.'.$this->value);
    }

    case Extracted = 'extracted';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function isFinal(): bool
    {
        return $this !== self::Extracted;
    }
}
