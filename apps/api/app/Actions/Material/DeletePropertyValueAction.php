<?php

namespace App\Actions\Material;

use App\Models\PropertyValue;

/** Removes one property value; the audit trail keeps what it was. */
final class DeletePropertyValueAction
{
    public function __invoke(PropertyValue $propertyValue): void
    {
        $propertyValue->delete();
    }
}
