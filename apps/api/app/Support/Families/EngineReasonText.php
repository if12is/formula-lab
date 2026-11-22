<?php

namespace App\Support\Families;

/** The words of an engine reason key: families.* reasons live in lang families.reasons, the rest in lang engine. */
final class EngineReasonText
{
    public static function of(string $reason): string
    {
        $head = trim(explode(':', $reason)[0]);

        if (str_starts_with($head, 'families.')) {
            return __('families.reasons.'.substr($head, strlen('families.')));
        }

        $key = "engine.{$head}";
        $text = __($key);

        return $text === $key ? __('families.reasons.unknown', ['reason' => $reason]) : $text;
    }
}
