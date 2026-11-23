<?php

namespace App\Exceptions\Domain;

/** Thrown when an item of an EDA notification file cannot be recorded (N-08). */
class EdaChecklistException extends BusinessRuleViolationException
{
    public static function itemUnknown(string $code): self
    {
        return new self(__('exceptions.eda_item_unknown', ['item' => $code]));
    }

    public static function itemsNotSourced(): self
    {
        return new self(__('exceptions.eda_items_not_sourced'));
    }

    public static function dataInvalid(): self
    {
        return new self(__('exceptions.eda_data_invalid'));
    }
}
