<?php

namespace App\Exceptions\Domain;

/** Thrown when the assistant cannot answer: not configured, the provider failed, or a request is outside what it accepts. */
class AssistantException extends BusinessRuleViolationException
{
    public function __construct(string $message, public readonly string $reason, public readonly int $status = 502)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(__('exceptions.assistant_not_configured'), 'not_configured', 503);
    }

    public static function unreachable(): self
    {
        return new self(__('exceptions.assistant_unreachable'), 'unreachable', 502);
    }

    public static function providerError(int $status, string $type): self
    {
        return new self(__('exceptions.assistant_provider_error', ['status' => $status, 'type' => $type]), 'provider_'.$status, 502);
    }

    public static function invalidAnswer(): self
    {
        return new self(__('exceptions.assistant_invalid_answer'), 'invalid_answer', 502);
    }

    public static function toolRoundsExceeded(int $limit): self
    {
        return new self(__('exceptions.assistant_tool_rounds_exceeded', ['limit' => $limit]), 'tool_rounds_exceeded', 502);
    }

    public static function unknownTool(string $name): self
    {
        return new self(__('exceptions.assistant_unknown_tool', ['tool' => $name]), 'unknown_tool', 422);
    }

    public static function invalidToolInput(string $tool, string $detail): self
    {
        return new self(__('exceptions.assistant_invalid_tool_input', ['tool' => $tool, 'detail' => $detail]), 'invalid_tool_input', 422);
    }
}
