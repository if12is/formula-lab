<?php

namespace App\Support\Coa;

use App\Enums\Coa\CoaFieldIssue;
use App\Enums\Coa\CoaReadingConfidence;
use App\Exceptions\Domain\EngineException;
use App\Support\Assistant\NumberGuard;
use App\Support\DecimalCompare;
use App\Support\Engine\EngineRunner;
use App\Support\PropertyRegistry;

/**
 * The deterministic checks on one field the model proposed (P-05). The model only reads: here, in code, a value is accepted, emptied or
 * converted, and every finding is recorded as an issue. Nothing is computed by the model and nothing is guessed:
 *   - the property must be in the property registry (and not one a COA never sets);
 *   - each of value / min / max must be one plain number, and, when the document text is known, a number that is in it (else it is emptied);
 *   - a unit is its registry spelling (config coa.unit_aliases), or is converted to a unit the property is stored in by the engine's exact table;
 *   - a range is ordered; a field is a value or a range, never both.
 * A quote the model gives must be in the document text too; when it is not, the reading confidence falls to low.
 */
final class CoaFieldChecker
{
    private const NUMBER_ONLY = '/^\s*(-?)(\d+(?:[.,]\d+)*)\s*$/u';

    public function __construct(
        private readonly NumberGuard $guard,
        private readonly EngineRunner $engine,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  One entry of the model's `fields`.
     * @param  CoaDocumentText  $document  What is known of the document's text.
     * @return array{
     *     property_as_printed: string|null, property_key: string|null, value: string|null, min: string|null, max: string|null, unit: string|null,
     *     as_printed: array<string, string|null>, converted_from: string|null, method: string|null, quote: string|null, page: int|null,
     *     reading_confidence: CoaReadingConfidence, issues: list<string>
     * }
     */
    public function check(array $raw, CoaDocumentText $document): array
    {
        $issues = [];
        $printed = [
            'value' => $this->text($raw['value'] ?? null),
            'min' => $this->text($raw['min'] ?? null),
            'max' => $this->text($raw['max'] ?? null),
            'unit' => $this->text($raw['unit'] ?? null),
        ];

        $key = $this->propertyKey($raw['propertyKey'] ?? null, $issues);
        $numbers = [];
        foreach (['value', 'min', 'max'] as $part) {
            $numbers[$part] = $this->number($printed[$part], $document, $issues);
        }

        if (! $this->blocked($issues)) {
            $this->checkShape($numbers, $issues);
        }

        [$numbers, $unit, $convertedFrom] = $this->unit($key, $printed['unit'], $numbers, $issues);

        if ($document->checked() === false) {
            $issues[] = CoaFieldIssue::Unverifiable->value;
        }

        $quote = $this->text($raw['quote'] ?? null);
        $confidence = CoaReadingConfidence::tryFrom((string) ($raw['readingConfidence'] ?? '')) ?? CoaReadingConfidence::Low;
        if ($quote !== null && $document->checked() && ! $document->contains($quote)) {
            $issues[] = CoaFieldIssue::QuoteNotInDocument->value;
            $confidence = CoaReadingConfidence::Low;
        }

        return [
            'property_as_printed' => $this->text($raw['propertyAsPrinted'] ?? null),
            'property_key' => $key,
            'value' => $numbers['value'],
            'min' => $numbers['min'],
            'max' => $numbers['max'],
            'unit' => $unit,
            'as_printed' => $printed,
            'converted_from' => $convertedFrom,
            'method' => $this->text($raw['method'] ?? null),
            'quote' => $quote,
            'page' => is_int($raw['page'] ?? null) && $raw['page'] > 0 ? $raw['page'] : null,
            'reading_confidence' => $confidence,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** @param  list<string>  $issues */
    private function propertyKey(mixed $key, array &$issues): ?string
    {
        if (is_string($key) && in_array($key, PropertyRegistry::keys(), true) && ! in_array($key, (array) config('coa.excluded_properties'), true)) {
            return $key;
        }
        $issues[] = CoaFieldIssue::UnknownProperty->value;

        return null;
    }

    /**
     * One plain decimal string, or null when there is none or it cannot be trusted (the issue says why).
     *
     * @param  list<string>  $issues
     */
    private function number(?string $printed, CoaDocumentText $document, array &$issues): ?string
    {
        if ($printed === null) {
            return null;
        }

        if (preg_match(self::NUMBER_ONLY, $this->guard->normalize($printed), $match) !== 1) {
            $issues[] = CoaFieldIssue::NotNumeric->value;

            return null;
        }

        $candidates = $this->guard->candidatesOf($match[2]);
        // "1,234" is a thousands mark or a decimal comma: two readings are no reading.
        if (count($candidates) !== 1) {
            $issues[] = CoaFieldIssue::NotNumeric->value;

            return null;
        }

        if ($document->checked() && ! $document->hasNumber($candidates[0])) {
            $issues[] = CoaFieldIssue::ValueNotInDocument->value;

            return null;
        }

        return $match[1].$this->asPrinted($match[2], $candidates[0]);
    }

    /** The token with its separators read the one way the canonical number says, and its trailing zeros kept ("1,2610" -> "1.2610"): the figure's precision is the certificate's. */
    private function asPrinted(string $token, string $canonical): string
    {
        foreach ([str_replace(',', '', $token), str_replace(',', '.', $token)] as $plain) {
            if ($this->guard->candidatesOf($plain) === [$canonical]) {
                return $plain;
            }
        }

        return $canonical;
    }

    /**
     * @param  array<string, string|null>  $numbers
     * @param  list<string>  $issues
     */
    private function checkShape(array $numbers, array &$issues): void
    {
        $hasValue = $numbers['value'] !== null;
        $hasRange = $numbers['min'] !== null || $numbers['max'] !== null;

        if ($hasValue === $hasRange) {
            $issues[] = CoaFieldIssue::ValueShape->value;

            return;
        }
        if ($numbers['min'] !== null && $numbers['max'] !== null && DecimalCompare::compare($numbers['min'], $numbers['max']) > 0) {
            $issues[] = CoaFieldIssue::RangeOrder->value;
        }
    }

    /**
     * The unit in the property's own spelling; a different unit the engine can convert exactly is converted (and the printed one kept).
     *
     * @param  array<string, string|null>  $numbers
     * @param  list<string>  $issues
     * @return array{0: array<string, string|null>, 1: string|null, 2: string|null}
     */
    private function unit(?string $key, ?string $printedUnit, array $numbers, array &$issues): array
    {
        if ($key === null) {
            return [$numbers, null, null];
        }

        $allowed = PropertyRegistry::unitsFor($key);
        $unit = $this->spelled($printedUnit);

        if ($unit === null) {
            // A dimensionless property stored in one unit only (pH, HLB): the property says it, not the model.
            if (count($allowed) === 1 && $this->isDimensionless($allowed[0]) && $printedUnit === null) {
                return [$numbers, $allowed[0], null];
            }
            $issues[] = CoaFieldIssue::UnitNotAllowed->value;

            return [$numbers, null, null];
        }
        if (in_array($unit, $allowed, true)) {
            return [$numbers, $unit, null];
        }

        $converted = $this->convert($numbers, $unit, $allowed);
        if ($converted === null) {
            $issues[] = CoaFieldIssue::UnitNotAllowed->value;

            return [$numbers, null, null];
        }
        $issues[] = CoaFieldIssue::Converted->value;

        return [$converted['numbers'], $converted['unit'], $unit];
    }

    /**
     * @param  array<string, string|null>  $numbers
     * @param  list<string>  $allowed
     * @return array{numbers: array<string, string|null>, unit: string}|null
     */
    private function convert(array $numbers, string $from, array $allowed): ?array
    {
        // Nothing to convert (the value was emptied by a check): a unit is not accepted on a figure that is not there.
        if (array_filter($numbers, fn (?string $number): bool => $number !== null) === []) {
            return null;
        }

        foreach ($allowed as $target) {
            $result = $numbers;
            $ok = true;
            foreach (['value', 'min', 'max'] as $part) {
                if ($numbers[$part] === null) {
                    continue;
                }
                $converted = $this->engineConvert($numbers[$part], $from, $target);
                if ($converted === null) {
                    $ok = false;
                    break;
                }
                $result[$part] = $converted;
            }
            if ($ok) {
                return ['numbers' => $result, 'unit' => $target];
            }
        }

        return null;
    }

    /** The engine's exact conversion, or null when it has none between the two units (or would have to round). */
    private function engineConvert(string $value, string $from, string $to): ?string
    {
        try {
            $answer = $this->engine->run('assistant', ['op' => 'convert-units', 'input' => ['value' => $value, 'from' => $from, 'to' => $to]]);
        } catch (EngineException) {
            return null;
        }

        $result = $answer['value'] ?? null;
        if (($answer['ok'] ?? false) !== true || ! is_array($result) || ($result['rounded'] ?? true) !== false || ! is_string($result['value'] ?? null)) {
            return null;
        }

        return $result['value'];
    }

    private function spelled(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        $compact = mb_strtolower(str_replace([' ', "\u{00A0}"], '', $unit));

        // Read as a whole array: an alias such as "mpa.s" has a dot in its key, which config()'s dot notation would split.
        return (string) (((array) config('coa.unit_aliases'))[$compact] ?? $unit);
    }

    private function isDimensionless(string $unit): bool
    {
        return in_array($unit, ['pH', 'HLB'], true);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 300);
    }

    /** @param  list<string>  $issues */
    private function blocked(array $issues): bool
    {
        return array_intersect($issues, [CoaFieldIssue::NotNumeric->value, CoaFieldIssue::ValueNotInDocument->value]) !== [];
    }
}
