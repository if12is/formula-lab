<?php

namespace App\Support\Coa;

use App\Exceptions\Domain\CoaException;
use App\Integrations\Anthropic\MessageResponse;
use JsonException;

/** Reads the model's reply to a COA request: the forced `report_coa` call, or (a model that wrote it as text) a JSON object. Anything else is unreadable and nothing is stored. */
final class CoaAnswerParser
{
    /** @throws CoaException When the reply is not the structure that was asked for. */
    public function parse(MessageResponse $response): CoaAnswer
    {
        $input = $this->input($response);

        $fields = $input['fields'] ?? null;
        if (! is_array($fields) || ! array_is_list($fields) || count($fields) > (int) config('coa.max_fields')) {
            throw CoaException::unreadableAnswer();
        }
        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['propertyAsPrinted'] ?? null)) {
                throw CoaException::unreadableAnswer();
            }
        }

        return new CoaAnswer(
            $this->text($input['supplier'] ?? null),
            $this->text($input['lotNo'] ?? null),
            $this->date($input['documentDate'] ?? null),
            $fields,
            $input,
        );
    }

    /** @return array<string, mixed> */
    private function input(MessageResponse $response): array
    {
        foreach ($response->toolUses() as $use) {
            if ($use['name'] === CoaReportSchema::TOOL) {
                return $use['input'];
            }
        }

        // No tool call: the text must be one JSON object (a code fence around it is tolerated).
        $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $response->text()));
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw CoaException::unreadableAnswer();
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : throw CoaException::unreadableAnswer();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 200) : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
