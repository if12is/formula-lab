<?php

namespace App\Exceptions\Domain;

/** Thrown when a packaging BOM cannot be saved or read (S-12). Messages come from lang/{ar,en}/packaging.php. */
class PackagingBomException extends BusinessRuleViolationException
{
    public static function imageNotStored(): self
    {
        return new self(__('packaging.bom.exceptions.image_not_stored'));
    }

    public static function imageMissing(): self
    {
        return new self(__('packaging.bom.exceptions.image_missing'));
    }

    public static function lineOfAnotherBom(): self
    {
        return new self(__('packaging.bom.exceptions.line_of_another_bom'));
    }
}
