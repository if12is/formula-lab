<?php

namespace App\Support\Assistant;

/**
 * The number guard (P-04): decides whether every number in a model reply is one a tool produced in the same turn (or one the
 * user wrote). Pure and deterministic: no clock, no randomness, no I/O, no floats. A number is compared as a canonical decimal
 * string (Arabic-Indic "12.50" -> "12.5", "012.50" -> "12.5", "12,5" -> "12.5"), so only the digits matter; the %, the unit and the digit script
 * do not. A rounded or estimated figure is a different number and is therefore a violation.
 *
 * Not numbers: list markers at a line start ("1."), dates (2026-11-23, 23/11/2026), identifiers glued to Latin letters (PEG-40, C12-14,
 * H2O, L3; but pH5.5 is a number), and a number right after a standard or category word (ES 1562, ISO 22716, CAS 7732-18-5, IFRA category 4).
 *
 * Numbers spelled as words are out of scope: the guard reads digits only.
 */
final class NumberGuard
{
    /** U+0660..U+0669, the Arabic-Indic digits (written as escapes: app code holds no Arabic text). */
    private const ARABIC_INDIC = ["\u{0660}", "\u{0661}", "\u{0662}", "\u{0663}", "\u{0664}", "\u{0665}", "\u{0666}", "\u{0667}", "\u{0668}", "\u{0669}"];

    /** U+06F0..U+06F9, the Eastern Arabic-Indic digits. */
    private const EASTERN_ARABIC_INDIC = ["\u{06F0}", "\u{06F1}", "\u{06F2}", "\u{06F3}", "\u{06F4}", "\u{06F5}", "\u{06F6}", "\u{06F7}", "\u{06F8}", "\u{06F9}"];

    /** @param  list<string>  $referencePrefixes  Words after which a number is a code or category, not a quantity. */
    public function __construct(private readonly array $referencePrefixes = []) {}

    /** Latin digits for Arabic-Indic and Eastern Arabic-Indic ones; the Arabic decimal mark as "." and the percent signs unified; separators kept. */
    public function normalize(string $text): string
    {
        $text = str_replace(self::ARABIC_INDIC, range(0, 9), $text);
        $text = str_replace(self::EASTERN_ARABIC_INDIC, range(0, 9), $text);

        // Arabic decimal mark U+066B, thousands mark U+066C, percent sign U+066A, and the minus sign and en dash as a hyphen.
        return strtr($text, ["\u{066B}" => '.', "\u{066C}" => ',', "\u{066A}" => '%', "\u{2212}" => '-', "\u{2013}" => '-']);
    }

    /**
     * Every number in the text, in order.
     *
     * @return list<array{raw: string, candidates: list<string>}> `raw` as written (normalized digits); `candidates` its canonical forms
     *                                                            (two when a comma could be a thousands mark or a decimal mark).
     */
    public function extract(string $text): array
    {
        $text = $this->mask($this->normalize($text));
        $numbers = [];

        preg_match_all('/(?<![A-Za-z\d_.,])(-?)(\d+(?:,\d+)*(?:\.\d+)?|\.\d+)(?![\p{N}])/u', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $candidates = $this->candidates($match[2]);
            // A "-" counts as a sign only when it stands before the digits without a letter or digit before it (the lookbehind above).
            $sign = $match[1] === '-' ? '-' : '';
            $numbers[] = ['raw' => $match[1].$match[2], 'candidates' => array_map(fn (string $c): string => $c === '0' ? $c : $sign.$c, $candidates)];
        }

        return $numbers;
    }

    /**
     * The canonical numbers found in texts and in structured data (tool answers).
     *
     * @param  iterable<mixed>  $sources  Strings, numbers, or arrays holding them.
     * @param  list<string>  $identifierKeys  Array keys whose value is an identifier or checksum, skipped.
     * @return array<string, true>
     */
    public function collect(iterable $sources, array $identifierKeys = []): array
    {
        $found = [];
        $walk = function (mixed $value, ?string $key = null) use (&$walk, &$found, $identifierKeys): void {
            if ($key !== null && in_array($key, $identifierKeys, true)) {
                return;
            }
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk($child, is_string($childKey) ? $childKey : null);
                }

                return;
            }
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (! is_string($value)) {
                return;
            }
            foreach ($this->extract($value) as $number) {
                foreach ($number['candidates'] as $candidate) {
                    $found[$candidate] = true;
                }
            }
        };

        foreach ($sources as $source) {
            $walk($source);
        }

        return $found;
    }

    /**
     * The numbers of the reply that are not in `$allowed`, each once, as written.
     *
     * @param  array<string, true>  $allowed  Canonical numbers, from collect().
     * @return list<string>
     */
    public function violations(string $reply, array $allowed): array
    {
        $violations = [];
        foreach ($this->extract($reply) as $number) {
            $supported = array_filter($number['candidates'], fn (string $candidate): bool => isset($allowed[$candidate]));
            if ($supported === [] && ! in_array($number['raw'], $violations, true)) {
                $violations[] = $number['raw'];
            }
        }

        return $violations;
    }

    /**
     * Every digit run of a text as canonical numbers, with none of the masking `extract()` does (dates, identifiers, standard codes stay numbers).
     * For checking that a figure appears in a document at all; a figure that is in the text is found, whatever the words around it.
     *
     * @return array<string, true>
     */
    public function plainNumbers(string $text): array
    {
        $found = [];
        preg_match_all('/\d+(?:[.,]\d+)*/', $this->normalize($text), $matches);
        foreach ($matches[0] as $token) {
            foreach ($this->candidatesOf($token) as $candidate) {
                $found[$candidate] = true;
            }
        }

        return $found;
    }

    /**
     * The canonical forms of one unsigned token ("1,234.50" -> "1234.5", "12,5" -> "12.5").
     *
     * @return list<string>
     */
    public function candidatesOf(string $token): array
    {
        return $this->candidates($token);
    }

    /** Blanks out everything that looks like a number but is not one (see the class note). */
    private function mask(string $text): string
    {
        $blank = fn (array $m): string => str_repeat(' ', mb_strlen($m[0]));
        // pH is a quantity ("pH5.5"), not an identifier, so its digits stay visible; Arabic letters never glue to a number (the conjunction "and" written before a digit leaves it a number).
        $text = (string) preg_replace('/(?<![A-Za-z])pH(?=[\d.])/i', 'pH ', $text);

        $patterns = [
            // Dates, ISO and day/month/year.
            '/\d{4}-\d{2}-\d{2}/u',
            '/\d{1,2}\/\d{1,2}\/\d{2,4}/u',
            // List markers at a line start: "1.", "2)", "- 3.", "**4.**".
            '/(?m)^[ \t]*(?:[-*•][ \t]*)?\**\d{1,3}\**[.)]\**[ \t]/u',
        ];
        foreach ($patterns as $pattern) {
            $text = (string) preg_replace_callback($pattern, $blank, $text);
        }
        // Identifiers: Latin letters glued to digits (PEG-40, C12-14, H2O, L3, E330), with hyphenated digit groups.
        $text = (string) preg_replace_callback('/(?<![A-Za-z\d])[A-Za-z]+[-_]?\d+(?:[-.]\d+)*(?:[A-Za-z][A-Za-z\d]*)?/u', $blank, $text);

        if ($this->referencePrefixes !== []) {
            $words = implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), $this->referencePrefixes));
            $text = (string) preg_replace_callback('/(?<![A-Za-z\d])(?:'.$words.')[ \t\-]*\d+(?:[-.:\/]\d+)*/iu', $blank, $text);
        }

        return $text;
    }

    /**
     * Canonical forms of one unsigned token such as "1,234.50", "12,5" or ".5".
     *
     * @return list<string>
     */
    private function candidates(string $token): array
    {
        if (! str_contains($token, ',')) {
            return [$this->canonical($token)];
        }

        $candidates = [];
        // Thousands marks: every group after the first has exactly three digits and there is no decimal part before the last comma.
        if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $token) === 1) {
            $candidates[] = $this->canonical(str_replace(',', '', $token));
        }
        // A decimal comma: one comma, no dot ("12,5", and "1,234" read as 1.234).
        if (substr_count($token, ',') === 1 && ! str_contains($token, '.')) {
            $candidates[] = $this->canonical(str_replace(',', '.', $token));
        }
        if ($candidates === []) {
            // Anything else (1,2,3 / 12,34.5): each part is its own number-like group; keep the digits as written without the commas.
            $candidates[] = $this->canonical(str_replace(',', '', $token));
        }

        return array_values(array_unique($candidates));
    }

    private function canonical(string $token): string
    {
        $token = ltrim($token, '+');
        if (str_starts_with($token, '.')) {
            $token = '0'.$token;
        }
        if (str_contains($token, '.')) {
            $token = rtrim(rtrim($token, '0'), '.');
        }
        $token = ltrim($token, '0');
        if ($token === '' || str_starts_with($token, '.')) {
            $token = '0'.$token;
        }

        return $token === '0' ? '0' : $token;
    }
}
