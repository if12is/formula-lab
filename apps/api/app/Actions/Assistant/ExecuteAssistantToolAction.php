<?php

namespace App\Actions\Assistant;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Support\Assistant\AssistantToolRegistry;
use App\Support\Assistant\NumberGuard;
use App\Support\Assistant\ToolContext;

/**
 * Runs one tool call of the model and returns the content of its tool_result block. A number the model passes as input must be one the
 * user wrote or an earlier tool answered with: the model cannot launder an invented figure through a tool. A failing tool is an error
 * result the model reads (and tells the user), never an exception that ends the question.
 */
final class ExecuteAssistantToolAction
{
    public function __construct(
        private readonly AssistantToolRegistry $tools,
        private readonly NumberGuard $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, true>  $userNumbers  Canonical numbers the user wrote.
     * @return array{content: string, is_error: bool}
     */
    public function __invoke(string $toolUseId, string $name, array $input, array $userNumbers, ToolContext $context): array
    {
        $identifierKeys = (array) config('assistant.guard.identifier_keys');

        try {
            $tool = $this->tools->get($name);

            $unsupported = $this->guard->violations(
                (string) json_encode($this->withoutIdentifiers($input, $identifierKeys), JSON_UNESCAPED_UNICODE),
                $userNumbers + $this->guard->collect($context->results(), $identifierKeys),
            );
            if ($unsupported !== []) {
                return ['content' => __('assistant.tool_input_unsupported', ['numbers' => implode(', ', $unsupported)], 'ar'), 'is_error' => true];
            }

            $result = $tool($input, $context);
        } catch (BusinessRuleViolationException $exception) {
            return ['content' => __('assistant.tool_failed', ['reason' => $exception->getMessage()], 'ar'), 'is_error' => true];
        }

        $context->record($toolUseId, $name, $result);

        return ['content' => (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'is_error' => false];
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $identifierKeys
     * @return array<mixed>
     */
    private function withoutIdentifiers(array $data, array $identifierKeys): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, $identifierKeys, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->withoutIdentifiers($value, $identifierKeys) : $value;
        }

        return $clean;
    }
}
