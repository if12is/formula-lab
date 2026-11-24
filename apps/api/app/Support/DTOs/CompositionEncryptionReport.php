<?php

namespace App\Support\DTOs;

/** What `crypto:encrypt-compositions` found and did, summed over the tenants it visited. */
final readonly class CompositionEncryptionReport
{
    /** @param  list<array{version: string, reason: string}>  $failures */
    public function __construct(
        public bool $dryRun,
        public int $versionsScanned = 0,
        public int $versionsPending = 0,
        public int $versionsEncrypted = 0,
        public int $columnsEncrypted = 0,
        public int $columnsAlreadyEncrypted = 0,
        public array $failures = [],
    ) {}

    public function merge(self $other): self
    {
        return new self(
            $this->dryRun,
            $this->versionsScanned + $other->versionsScanned,
            $this->versionsPending + $other->versionsPending,
            $this->versionsEncrypted + $other->versionsEncrypted,
            $this->columnsEncrypted + $other->columnsEncrypted,
            $this->columnsAlreadyEncrypted + $other->columnsAlreadyEncrypted,
            [...$this->failures, ...$other->failures],
        );
    }

    public function succeeded(): bool
    {
        return $this->failures === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
