<?php

namespace App\Actions\Security;

use Illuminate\Support\Str;

/**
 * Removes, at any depth, every key that matches one of the denied patterns. Patterns are matched against the snake_case form of the
 * key with `*` as the wildcard; keys whose snake_case form is in $exempt are kept untouched. Lists are walked, scalars are left alone. Pure: no config, no user.
 */
final class StripHiddenFieldsAction
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $deniedPatterns
     * @param  list<string>  $exempt
     * @return array<array-key, mixed>
     */
    public function __invoke(array $payload, array $deniedPatterns, array $exempt = []): array
    {
        if ($deniedPatterns === []) {
            return $payload;
        }
        $regex = '/^(?:'.implode('|', array_map(fn (string $pattern): string => str_replace('\*', '.*', preg_quote($pattern, '/')), $deniedPatterns)).')$/';

        return $this->walk($payload, $regex, $exempt);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $exempt
     * @return array<array-key, mixed>
     */
    private function walk(array $node, string $regex, array $exempt): array
    {
        $kept = [];
        foreach ($node as $key => $value) {
            $snake = is_string($key) ? Str::snake($key) : null;
            $isExempt = $snake !== null && in_array($snake, $exempt, true);
            if ($snake !== null && ! $isExempt && preg_match($regex, $snake) === 1) {
                continue;
            }
            $kept[$key] = is_array($value) && ! $isExempt ? $this->walk($value, $regex, $exempt) : $value;
        }

        return $kept;
    }
}
