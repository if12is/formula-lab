<?php

namespace App\Support\DTOs;

/** Response of POST /api/sanad/v1/changes (contract section 2). */
final readonly class SanadChangesResult
{
    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<array{entity: string, uuid: string, reason: string}>  $skipped  items of an entity this version does not know
     */
    public function __construct(
        public int $accepted,
        public ?string $cursor,
        public array $conflicts,
        public array $skipped = [],
    ) {}

    /** @return array{accepted: int, cursor: ?string, conflicts: list<array<string, mixed>>, skipped: list<array<string, string>>} */
    public function toArray(): array
    {
        return ['accepted' => $this->accepted, 'cursor' => $this->cursor, 'conflicts' => $this->conflicts, 'skipped' => $this->skipped];
    }
}
