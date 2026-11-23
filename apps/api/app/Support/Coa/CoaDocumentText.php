<?php

namespace App\Support\Coa;

use App\Support\Assistant\NumberGuard;

/**
 * What the server knows of a document's text: the text itself (a PDF with a text layer) or nothing (a scan or an image, whose numbers cannot
 * be checked by code). Answers whether a number or a phrase is in it.
 */
final class CoaDocumentText
{
    /** @var array<string, true> */
    private array $numbers;

    private function __construct(
        private readonly ?string $text,
        private readonly NumberGuard $guard,
    ) {
        $this->numbers = $text === null ? [] : $guard->plainNumbers($text);
    }

    public static function of(?string $text, NumberGuard $guard): self
    {
        return new self($text, $guard);
    }

    /** Whether the text is known, so what the model returned can be checked against it. */
    public function checked(): bool
    {
        return $this->text !== null;
    }

    public function hasNumber(string $canonical): bool
    {
        return isset($this->numbers[ltrim($canonical, '-')]);
    }

    /** A phrase is in the text, ignoring case, spacing and the digit script. */
    public function contains(string $phrase): bool
    {
        return $this->text !== null && str_contains($this->flatten($this->text), $this->flatten($phrase));
    }

    private function flatten(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($this->guard->normalize($text))));
    }
}
