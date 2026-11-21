<?php

namespace App\Support\DTOs;

/** Result of publishing a material to Sanad: queued in the outbox (link mode) or upserted over LAN. */
final readonly class SanadPublishResult
{
    /** @param  array<string, mixed>|null  $response  Sanad's reply in LAN mode */
    public function __construct(
        public string $productUuid,
        public string $mode,
        public ?string $outboxId = null,
        public ?array $response = null,
    ) {}

    /** @return array{product_uuid: string, mode: string, outbox_id: ?string, response: ?array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'product_uuid' => $this->productUuid,
            'mode' => $this->mode,
            'outbox_id' => $this->outboxId,
            'response' => $this->response,
        ];
    }
}
