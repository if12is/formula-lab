<?php

namespace App\Support\DTOs;

use App\Enums\Sanad\SanadChangeStatus;
use App\Enums\Sanad\SanadConflictReason;
use App\Models\SanadRef;
use Carbon\CarbonInterface;

/** Result of applying one pushed Sanad item. */
final readonly class SanadChangeOutcome
{
    public function __construct(
        public SanadChangeStatus $status,
        public ?SanadConflictReason $reason = null,
        public ?CarbonInterface $localUpdatedAt = null,
        public ?SanadRef $ref = null,
    ) {}

    public static function applied(?SanadRef $ref = null): self
    {
        return new self(SanadChangeStatus::Applied, ref: $ref);
    }

    public static function unchanged(?SanadRef $ref = null): self
    {
        return new self(SanadChangeStatus::Unchanged, ref: $ref);
    }

    public static function conflict(SanadConflictReason $reason, ?CarbonInterface $localUpdatedAt = null): self
    {
        return new self(SanadChangeStatus::Conflict, $reason, $localUpdatedAt);
    }

    public function isConflict(): bool
    {
        return $this->status === SanadChangeStatus::Conflict;
    }

    /** @return array{status: string, reason: ?string, local_updated_at: ?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason?->value,
            'local_updated_at' => $this->localUpdatedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
