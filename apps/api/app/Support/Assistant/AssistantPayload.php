<?php

namespace App\Support\Assistant;

/**
 * The provider-facing shape of every request the app makes to the model (P-08): sampling, prompt caching and structured tool inputs are decided
 * here once, from config/assistant.php, so the assistant turn and the COA reading cannot drift apart.
 *
 * Render order at the provider is tools, then system, then messages. A cache breakpoint on the last tool and one on the system block therefore
 * cache both, while the changing part (the conversation) stays after them.
 */
final class AssistantPayload
{
    /** Schema keywords a strict tool cannot carry (the provider rejects or ignores them); the app validates them itself after the call. */
    private const UNSUPPORTED_SCHEMA_KEYS = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems', 'pattern'];

    /**
     * `temperature` as configured, or nothing at all when the config leaves it out (some models reject any value).
     *
     * @return array{temperature?: int|float}
     */
    public static function sampling(): array
    {
        $temperature = config('assistant.temperature');
        if (! is_numeric($temperature)) {
            return [];
        }

        return ['temperature' => $temperature == (int) $temperature ? (int) $temperature : (float) $temperature];
    }

    /** @return array{type: string, ttl?: string}|null The breakpoint marker, or null when caching is off. */
    public static function cacheControl(): ?array
    {
        if (! (bool) config('assistant.prompt_cache.enabled')) {
            return null;
        }

        return ['type' => 'ephemeral', ...(config('assistant.prompt_cache.ttl') === '1h' ? ['ttl' => '1h'] : [])];
    }

    /** @return list<array<string, mixed>> The system prompt as one cacheable text block. */
    public static function system(string $text): array
    {
        return [['type' => 'text', 'text' => $text, ...self::marker()]];
    }

    /**
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $definitions
     * @return list<array<string, mixed>> The tools with strict inputs (when enabled) and the breakpoint on the last one.
     */
    public static function tools(array $definitions): array
    {
        $strict = (bool) config('assistant.structured_outputs');
        $tools = [];
        foreach (array_values($definitions) as $index => $definition) {
            $tools[] = [
                ...$definition,
                ...($strict ? ['input_schema' => self::strictSchema($definition['input_schema']), 'strict' => true] : []),
                ...($index === count($definitions) - 1 ? self::marker() : []),
            ];
        }

        return $tools;
    }

    /**
     * A JSON schema without the keywords a strict tool does not support, at every depth.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function strictSchema(array $schema): array
    {
        $clean = [];
        foreach ($schema as $key => $value) {
            if (is_string($key) && in_array($key, self::UNSUPPORTED_SCHEMA_KEYS, true)) {
                continue;
            }
            // The names under `properties` are the tool's own field names, not keywords: only each field's schema is cleaned.
            $clean[$key] = match (true) {
                $key === 'properties' && is_array($value) => array_map(fn (mixed $field): mixed => is_array($field) ? self::strictSchema($field) : $field, $value),
                is_array($value) => self::strictSchema($value),
                default => $value,
            };
        }

        return $clean;
    }

    /** @return array{cache_control?: array{type: string, ttl?: string}} */
    private static function marker(): array
    {
        $control = self::cacheControl();

        return $control === null ? [] : ['cache_control' => $control];
    }
}
