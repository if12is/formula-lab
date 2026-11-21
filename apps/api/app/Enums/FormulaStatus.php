<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The approval workflow of a formula version (O-02): draft -> lab_testing -> approved | rejected.
 * Approved and rejected are final: a change is always a new version.
 */
enum FormulaStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case LabTesting = 'lab_testing';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** @return list<self> The statuses a version in this status may move to. */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::LabTesting],
            self::LabTesting => [self::Approved, self::Rejected],
            self::Approved, self::Rejected => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }
}
