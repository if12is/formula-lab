<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * The lang/<locale>/*.php groups the frontend needs, flattened to "group.key" => text.
 * Laravel's lang files are the only place UI text is written; the frontend reads this
 * dictionary through its t() composable (resources/js/composables/useI18n.js).
 */
final class Translations
{
    /**
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        $dictionary = [];

        foreach (config('ui.shared_groups') as $group) {
            $lines = trans($group, [], $locale);

            if (is_array($lines)) {
                foreach (Arr::dot($lines) as $key => $text) {
                    $dictionary["{$group}.{$key}"] = (string) $text;
                }
            }
        }

        return $dictionary;
    }
}
