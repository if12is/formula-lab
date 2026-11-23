<?php

namespace App\Support\Assistant;

/** The system prompt of the assistant, built from lang/ar/assistant.php: Egyptian Arabic whatever the interface language is. */
final class AssistantPrompt
{
    public const LOCALE = 'ar';

    public static function system(string $locale = self::LOCALE): string
    {
        $prompt = (array) __('assistant.prompt', [], $locale);

        return $prompt['intro']."\n\n".$prompt['rules_title']."\n".implode("\n", array_map(
            fn (string $rule, int $index): string => ($index + 1).'. '.$rule,
            $prompt['rules'],
            array_keys($prompt['rules']),
        ));
    }
}
