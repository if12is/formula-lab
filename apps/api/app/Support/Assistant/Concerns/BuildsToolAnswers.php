<?php

namespace App\Support\Assistant\Concerns;

use App\Exceptions\Domain\AssistantException;
use Illuminate\Support\Facades\Validator;

/** The envelope every tool answers in, and the strict reading of its input. */
trait BuildsToolAnswers
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules  Rules by key; a key with no rule is refused (the schema is strict).
     * @return array<string, mixed>
     *
     * @throws AssistantException When a key is unknown or a value is invalid.
     */
    protected function validated(array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($this->inputSchema()['properties']));
        if ($unknown !== []) {
            throw AssistantException::invalidToolInput($this->name(), implode(', ', $unknown));
        }

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw AssistantException::invalidToolInput($this->name(), implode('; ', $validator->errors()->all()));
        }

        return $validator->validated();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @return array{ok: bool, data: array<string, mixed>, missing: list<array<string, mixed>>, warnings: list<string>}
     */
    protected function answer(array $data, array $warnings = []): array
    {
        return ['ok' => true, 'data' => $data, 'missing' => [], 'warnings' => array_values($warnings)];
    }

    /**
     * @param  list<array<string, mixed>>  $missing
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @return array{ok: bool, data: array<string, mixed>, missing: list<array<string, mixed>>, warnings: list<string>}
     */
    protected function missing(array $missing, array $data = [], array $warnings = []): array
    {
        return ['ok' => false, 'data' => $data, 'missing' => array_values($missing), 'warnings' => array_values($warnings)];
    }

    /**
     * An engine gap as the model reads it: what is missing and why, without the screen wording a service adds (subject, text).
     *
     * @param  list<array<string, mixed>>  $gaps
     * @return list<array<string, mixed>>
     */
    protected function plainGaps(array $gaps): array
    {
        return array_values(array_map(
            fn (array $gap): array => array_filter(array_intersect_key($gap, ['materialId' => 1, 'property' => 1, 'reason' => 1]), fn (mixed $part): bool => $part !== null),
            $gaps,
        ));
    }
}
