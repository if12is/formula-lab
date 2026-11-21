<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What kind of document or system a reference value came from. */
enum SourceKind: string
{
    use HasOptions;

    case SupplierTds = 'supplier_tds';
    case Coa = 'coa';
    case Standard = 'standard';
    case RegulatoryDb = 'regulatory_db';
    case Reference = 'reference';
    case Community = 'community';
    case LabMeasurement = 'lab_measurement';
    /** System-made: the shared source of prices typed into the formula editor. Never offered in forms. */
    case UnverifiedEntry = 'unverified_entry';

    /**
     * The kinds a person may pick when creating a source.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function selectableOptions(): array
    {
        return array_values(array_filter(
            self::options(),
            fn (array $option): bool => $option['value'] !== self::UnverifiedEntry->value,
        ));
    }

    /** The label of a stored kind, or the raw text for a kind this enum does not know (older imported rows). */
    public static function labelFor(string $kind): string
    {
        return self::tryFrom($kind)?->label() ?? $kind;
    }
}
