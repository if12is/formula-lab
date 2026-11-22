<?php

namespace App\Exceptions\Domain;

/** Thrown when the publish-to-Sanad decision rule set blocks the push; carries each failed rule's message and remedy. */
class FinishedGoodPublishBlockedException extends BusinessRuleViolationException
{
    /** @param  list<array{rule: string, message: string, remedy: string}>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(__('exceptions.publish_blocked', ['reasons' => implode(' ', array_column($reasons, 'message'))]));
    }

    /** @param  string  $detail  What the engine could not judge. */
    public static function undecidable(string $detail): self
    {
        $exception = new self([]);
        $exception->message = __('exceptions.publish_undecidable', ['detail' => $detail]);

        return $exception;
    }
}
