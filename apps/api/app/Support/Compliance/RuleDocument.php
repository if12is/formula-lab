<?php

namespace App\Support\Compliance;

use App\Models\Source;

/** Small helpers on one rule in the engine's rules DSL. */
final class RuleDocument
{
    /** The message key of a rule typed in the interface: its wording is the rule's own `message`, this key is the fallback. */
    public const CUSTOM_MESSAGE_KEY = 'compliance.rule.custom';

    /**
     * A rule's `source.title` is the title of the source it points at, read from the database, so the two never disagree.
     *
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    public static function withSourceTitle(array $rule): array
    {
        $source = $rule['source'] ?? null;

        if (! is_array($source) || ! isset($source['sourceId'])) {
            return $rule;
        }

        $title = Source::query()->whereKey($source['sourceId'])->value('title');

        return [...$rule, 'source' => [...$source, 'title' => (string) ($title ?? $source['title'] ?? '')]];
    }

    /**
     * The key of the single target of a rule (substanceCas, inci, function, materialId, field).
     *
     * @param  array<string, mixed>  $rule
     */
    public static function targetType(array $rule): ?string
    {
        $target = $rule['target'] ?? [];

        return is_array($target) ? (array_key_first($target) === null ? null : (string) array_key_first($target)) : null;
    }
}
