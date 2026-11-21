<?php

namespace App\Exceptions;

use DomainException;

/** Thrown when code tries to change or delete a record that must stay immutable. */
class ImmutableRecordException extends DomainException
{
    public static function approvedFormulaVersion(string $versionId): self
    {
        return new self("Formula version {$versionId} is approved and immutable; create a new version instead.");
    }

    public static function appendOnly(string $table): self
    {
        return new self("{$table} is append-only: update and delete are not allowed.");
    }
}
