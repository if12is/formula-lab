<?php

namespace App\Exceptions\Domain;

/** Thrown when a call to the calculation engine (a Node process, see EngineRunner) cannot give an answer. */
class EngineException extends BusinessRuleViolationException
{
    public static function timeout(string $script, int $seconds): self
    {
        return new self(__('exceptions.engine_timeout', ['script' => $script, 'seconds' => $seconds]));
    }

    public static function failed(string $script, int $exitCode, string $detail): self
    {
        return new self(__('exceptions.engine_failed', ['script' => $script, 'code' => $exitCode, 'detail' => $detail]));
    }

    public static function invalidInput(string $script): self
    {
        return new self(__('exceptions.engine_invalid_input', ['script' => $script]));
    }

    public static function invalidOutput(string $script): self
    {
        return new self(__('exceptions.engine_invalid_output', ['script' => $script]));
    }

    public static function unknownScript(string $script): self
    {
        return new self(__('exceptions.engine_unknown_script', ['script' => $script]));
    }
}
