<?php

namespace App\Support\DTOs;

/** One page of a Sanad read endpoint (contract 4.1): `{ "data": [...], "next_cursor": "..."|null }`. */
final readonly class SanadPage
{
    /** @param  list<array<string, mixed>>  $data */
    public function __construct(
        public array $data,
        public ?string $nextCursor,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function toArray(): array
    {
        return ['data' => $this->data, 'next_cursor' => $this->nextCursor];
    }
}
