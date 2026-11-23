<?php

namespace App\Enums\Coa;

use App\Enums\Concerns\HasOptions;

/** What the deterministic checks found wrong with an extracted field. A `blocking` issue leaves the value empty: the reviewer types it or rejects the field. */
enum CoaFieldIssue: string
{
    use HasOptions;

    /** The labels live in lang/{ar,en}/coa.php (`coa.enums.issue.<value>`), with the rest of the COA screen's text. */
    public function label(): string
    {
        return __('coa.enums.issue.'.$this->value);
    }

    /** The property name is not in the property registry. */
    case UnknownProperty = 'unknown_property';
    /** The printed unit is not one this property is stored in and the engine has no exact conversion to one that is. */
    case UnitNotAllowed = 'unit_not_allowed';
    /** A number the model returned is not a number of the document text. */
    case ValueNotInDocument = 'value_not_in_document';
    /** The value is not one plain number (text, two numbers, an ambiguous separator). */
    case NotNumeric = 'not_numeric';
    /** A range has its minimum above its maximum. */
    case RangeOrder = 'range_order';
    /** More than one of value / range was given, or none. */
    case ValueShape = 'value_shape';
    /** The quote the model gives is not in the document text. */
    case QuoteNotInDocument = 'quote_not_in_document';
    /** The document has no text layer (a scan or an image): the numbers could not be checked by the server. */
    case Unverifiable = 'unverifiable';
    /** The value was converted to the property's unit by the engine; the printed figure is kept. */
    case Converted = 'converted';

    /** Blocking: the field cannot be saved as it is. */
    public function blocking(): bool
    {
        return in_array($this, [self::UnknownProperty, self::UnitNotAllowed, self::ValueNotInDocument, self::NotNumeric, self::RangeOrder, self::ValueShape], true);
    }
}
