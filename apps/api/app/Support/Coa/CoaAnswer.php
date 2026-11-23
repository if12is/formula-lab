<?php

namespace App\Support\Coa;

/**
 * What the model answered for a COA, read out of its reply and not yet checked.
 *
 * @phpstan-type RawField array<string, mixed>
 */
final readonly class CoaAnswer
{
    /** @param  list<array<string, mixed>>  $fields */
    public function __construct(
        public ?string $supplier,
        public ?string $lotNo,
        public ?string $documentDate,
        public array $fields,
        /** @var array<string, mixed> */
        public array $raw,
    ) {}
}
