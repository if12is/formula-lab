<?php

namespace App\Support\DTOs;

/** Result of publishing a price list: queued in the outbox (link mode), sent over LAN, or nothing new to send. */
final readonly class PriceListPublishResult
{
    /** @param  list<array<string, mixed>>  $responses  Sanad's replies in LAN mode */
    public function __construct(
        public string $priceListId,
        public string $mode,
        public ?string $outboxId = null,
        public bool $unchanged = false,
        public array $responses = [],
    ) {}
}
