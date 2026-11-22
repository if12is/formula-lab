<?php

namespace App\Exceptions\Domain;

/**
 * Thrown when a formula cannot be started from a template: the engine reported what is missing or invalid (a line without a
 * material or share, a sum that is not 100), or the compliance check FAILed. `gaps` is the engine's `missing[]`, kept so the form
 * can show each problem on its own line.
 */
class TemplateUseRejectedException extends BusinessRuleViolationException
{
    /** @param  list<array{materialId?: string, property: string, reason: string}>  $gaps */
    public function __construct(string $message, public readonly array $gaps = [])
    {
        parent::__construct($message);
    }

    /** @param  list<array{materialId?: string, property: string, reason: string}>  $gaps */
    public static function fromEngine(array $gaps): self
    {
        return new self(__('exceptions.template_use_rejected', ['count' => count($gaps)]), $gaps);
    }
}
