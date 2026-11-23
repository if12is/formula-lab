<?php

namespace App\Support\DTOs;

use App\Enums\Assistant\AssistantRequestStatus;

/** The outcome of one question: the text for the user, whether it passed the number guard, and the tools that were called. */
final readonly class AssistantReply
{
    /** @param  list<string>  $tools  Names of the tools called, in order. */
    public function __construct(
        public string $text,
        public AssistantRequestStatus $status,
        public array $tools,
    ) {}

    /** @return array{reply: string, status: string, tools: list<string>} */
    public function toArray(): array
    {
        return ['reply' => $this->text, 'status' => $this->status->value, 'tools' => $this->tools];
    }
}
