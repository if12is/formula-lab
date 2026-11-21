<?php

namespace App\Support\DTOs;

/** Response of GET /api/sanad/v1/outbox (contract 3.1). */
final readonly class SanadOutboxPage
{
    /** @param  list<array<string, mixed>>  $data */
    public function __construct(
        public array $data,
        public ?string $nextCursor,
        public bool $hasMore,
    ) {}

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string, has_more: bool} */
    public function toArray(): array
    {
        return ['data' => $this->data, 'next_cursor' => $this->nextCursor, 'has_more' => $this->hasMore];
    }
}
