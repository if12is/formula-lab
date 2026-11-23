<?php

namespace App\Support\Assistant;

/** What the tools answered during one turn: the guard reads the numbers from here, and explain_result reads the answers. */
final class ToolContext
{
    /** @var list<array{id: string, name: string, result: array<string, mixed>}> */
    private array $results = [];

    /** @param  string|null  $consentKey  The browser session whose privacy consent gates the tools (P-10); null = no session, so no consent. */
    public function __construct(public readonly ?string $consentKey = null) {}

    /** @param  array<string, mixed>  $result */
    public function record(string $toolUseId, string $name, array $result): void
    {
        $this->results[] = ['id' => $toolUseId, 'name' => $name, 'result' => $result];
    }

    /** @return array<string, mixed>|null The latest answer of the tool in this turn. */
    public function latest(string $name): ?array
    {
        foreach (array_reverse($this->results) as $entry) {
            if ($entry['name'] === $name) {
                return $entry['result'];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> Every answer of the turn, in order. */
    public function results(): array
    {
        return array_column($this->results, 'result');
    }

    public function count(): int
    {
        return count($this->results);
    }
}
