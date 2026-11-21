<?php

namespace App\Support\DTOs;

/** Summary of a LAN-mode pull. The caller persists $cursors (one per item kind) and passes them back on the next run. */
final readonly class SanadPullResult
{
    /** @param  array<string, ?string>  $cursors */
    public function __construct(
        public int $accepted,
        public int $conflicts,
        public int $pages,
        public array $cursors,
        public bool $hasMore,
    ) {}

    /** @return array{accepted: int, conflicts: int, pages: int, cursors: array<string, ?string>, has_more: bool} */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'conflicts' => $this->conflicts,
            'pages' => $this->pages,
            'cursors' => $this->cursors,
            'has_more' => $this->hasMore,
        ];
    }
}
