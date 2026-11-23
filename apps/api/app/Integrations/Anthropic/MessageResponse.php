<?php

namespace App\Integrations\Anthropic;

/** The parts of a provider answer the assistant reads: the content blocks, why it stopped and the tokens it used. */
final readonly class MessageResponse
{
    /**
     * @param  list<array<string, mixed>>  $content  Blocks: `{type: text, text}` or `{type: tool_use, id, name, input}`.
     */
    public function __construct(
        public array $content,
        public string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
    ) {}

    /** The text blocks joined, which is the reply. */
    public function text(): string
    {
        return trim(implode("\n", array_map(
            fn (array $block): string => (string) ($block['text'] ?? ''),
            array_filter($this->content, fn (array $block): bool => ($block['type'] ?? null) === 'text'),
        )));
    }

    /** @return list<array{id: string, name: string, input: array<string, mixed>}> */
    public function toolUses(): array
    {
        $uses = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $uses[] = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => (array) ($block['input'] ?? [])];
            }
        }

        return $uses;
    }

    /** @return array{role: string, content: list<array<string, mixed>>} The blocks as they are echoed back as the assistant turn. */
    public function assistantMessage(): array
    {
        return ['role' => 'assistant', 'content' => $this->content];
    }
}
