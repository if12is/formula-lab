<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What kind of document or system a reference value came from. */
enum SourceKind: string
{
    use HasOptions;

    case SupplierTds = 'supplier_tds';
    /** A purchase invoice or supplier price document: the title is its number, the url its scan or Sanad link (I-08). */
    case SupplierInvoice = 'supplier_invoice';
    case Coa = 'coa';
    case Standard = 'standard';
    case RegulatoryDb = 'regulatory_db';
    case Reference = 'reference';
    case Community = 'community';
    case LabMeasurement = 'lab_measurement';
    /** System-made: the shared source of prices typed into the formula editor. Never offered in forms. */
    case UnverifiedEntry = 'unverified_entry';
    /** System-made: a decision the owner recorded (for example the tax a product may use). Never offered in forms. */
    case OwnerDecision = 'owner_decision';

    /**
     * The kinds a person may pick when creating a source.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function selectableOptions(): array
    {
        return array_values(array_filter(
            self::options(),
            fn (array $option): bool => ! in_array($option['value'], [self::UnverifiedEntry->value, self::OwnerDecision->value], true),
        ));
    }

    /** The label of a stored kind, or the raw text for a kind this enum does not know (older imported rows). */
    public static function labelFor(string $kind): string
    {
        return self::tryFrom($kind)?->label() ?? $kind;
    }
}
