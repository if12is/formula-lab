<?php

namespace App\Support\Assistant;

use App\Enums\Assistant\AssistantDataCategory;
use App\Exceptions\Domain\BusinessRuleViolationException;

/**
 * One tool the model may call. A tool is a thin wrapper: it checks the input against its strict schema, asks an existing engine
 * script or service, and returns the answer as structured data. It never computes a number itself.
 *
 * Every answer has the same envelope: `ok`, `data` (the tool's own structure, with the source and confidence of each value),
 * `missing` (a list of `{materialId?, property, reason}`: what stops an answer, never replaced by a zero) and `warnings`.
 */
interface AssistantTool
{
    /** The tool name the model calls. */
    public function name(): string;

    /** What the tool does and when to use it, written for the model. */
    public function description(): string;

    /**
     * The JSON schema of the input: an object with `additionalProperties: false` (strict).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * The kinds of the owner's data this call would put in front of the model provider (P-10). Empty for a tool that reads only public
     * reference data: it is never gated. A non-empty list is refused until the session has consented to each category.
     *
     * @param  array<string, mixed>  $input
     * @return list<AssistantDataCategory>
     */
    public function dataCategories(array $input): array;

    /**
     * @param  array<string, mixed>  $input  Already checked against the schema.
     * @return array{ok: bool, data: array<string, mixed>, missing: list<array<string, mixed>>, warnings: list<string>}
     *
     * @throws BusinessRuleViolationException When the engine or a service cannot answer.
     */
    public function __invoke(array $input, ToolContext $context): array;
}
