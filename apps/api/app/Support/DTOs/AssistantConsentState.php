<?php

namespace App\Support\DTOs;

use App\Enums\Assistant\AssistantDataCategory;
use Carbon\CarbonImmutable;

/** What a browser session has agreed to send to the model provider right now. */
final readonly class AssistantConsentState
{
    /** @param  list<AssistantDataCategory>  $categories  Empty when there is no active consent. */
    public function __construct(
        public array $categories = [],
        public ?CarbonImmutable $grantedAt = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function granted(): bool
    {
        return $this->categories !== [];
    }

    /** @param  list<AssistantDataCategory>  $needed  True when every needed category is covered (an empty list needs nothing). */
    public function allows(array $needed): bool
    {
        return array_diff(array_column($needed, 'value'), array_column($this->categories, 'value')) === [];
    }

    /** @return array{granted: bool, categories: list<string>, grantedAt: string|null, expiresAt: string|null} */
    public function toArray(): array
    {
        return [
            'granted' => $this->granted(),
            'categories' => array_column($this->categories, 'value'),
            'grantedAt' => $this->grantedAt?->toIso8601String(),
            'expiresAt' => $this->expiresAt?->toIso8601String(),
        ];
    }
}
