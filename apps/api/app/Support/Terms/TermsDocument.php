<?php

namespace App\Support\Terms;

use App\Support\CanonicalJson;

/**
 * The terms text as one versioned document: the sections written in lang/<locale>/terms.php, the version label from config/terms.php
 * and a hash of both languages' wording. A recorded acceptance stores the version and the hash, so what was agreed to can be proven
 * later, and editing the wording without a version bump still asks everyone again (the hash no longer matches).
 */
final class TermsDocument
{
    /** @var list<string> */
    private const LOCALES = ['ar', 'en'];

    public static function version(): string
    {
        return (string) config('terms.version');
    }

    public static function isDraft(): bool
    {
        return (bool) config('terms.is_draft');
    }

    /** @return list<array{key: string, title: string, body: string}> */
    public static function sections(?string $locale = null): array
    {
        $sections = [];

        foreach ((array) trans('terms.sections', [], $locale) as $key => $section) {
            $sections[] = ['key' => (string) $key, 'title' => (string) $section['title'], 'body' => (string) $section['body']];
        }

        return $sections;
    }

    /** SHA-256 over the canonical JSON of the version and every language's sections. */
    public static function hash(): string
    {
        $wording = [];
        foreach (self::LOCALES as $locale) {
            $wording[$locale] = self::sections($locale);
        }

        return CanonicalJson::hash(['version' => self::version(), 'wording' => $wording]);
    }
}
