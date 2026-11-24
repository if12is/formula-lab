<?php

namespace App\Support\DTOs;

/** What `crypto:rewrap-tenant-keys` found and did. */
final readonly class KeyRewrapReport
{
    public function __construct(
        public bool $dryRun,
        public int $tenants = 0,
        public int $rewrapped = 0,
        public int $alreadyCurrent = 0,
        public int $withoutKey = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
