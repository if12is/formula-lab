<?php

namespace App\Exceptions\Domain;

/** Thrown when a COA cannot be extracted or an extraction cannot be decided (P-05). Messages come from lang/{ar,en}/coa.php. */
class CoaException extends BusinessRuleViolationException
{
    /** The model's answer is not the structure that was asked for (not JSON, no `fields` list, a field that is not an object). */
    public static function unreadableAnswer(): self
    {
        return new self(__('coa.exceptions.unreadable_answer'));
    }

    public static function documentUnreadable(): self
    {
        return new self(__('coa.exceptions.document_unreadable'));
    }

    public static function alreadyDecided(string $status): self
    {
        return new self(__('coa.exceptions.already_decided', ['status' => __("coa.enums.status.{$status}")]));
    }

    public static function nothingAccepted(): self
    {
        return new self(__('coa.exceptions.nothing_accepted'));
    }

    public static function fieldNotOfExtraction(): self
    {
        return new self(__('coa.exceptions.field_not_of_extraction'));
    }

    public static function fileMissing(): self
    {
        return new self(__('coa.exceptions.file_missing'));
    }
}
