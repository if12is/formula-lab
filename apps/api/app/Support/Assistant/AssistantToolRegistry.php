<?php

namespace App\Support\Assistant;

use App\Exceptions\Domain\AssistantException;

/** The tools offered to the model (config/assistant.php `tools`), by name. */
final class AssistantToolRegistry
{
    /** @var array<string, AssistantTool>|null */
    private ?array $tools = null;

    /** @return array<string, AssistantTool> */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach ((array) config('assistant.tools') as $class) {
                $tool = app($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    /** @throws AssistantException When no tool has the name. */
    public function get(string $name): AssistantTool
    {
        return $this->all()[$name] ?? throw AssistantException::unknownTool($name);
    }

    /**
     * The tools as the provider's request wants them.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return array_values(array_map(fn (AssistantTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchema(),
        ], $this->all()));
    }
}
