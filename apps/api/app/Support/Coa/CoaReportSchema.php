<?php

namespace App\Support\Coa;

use App\Support\PropertyRegistry;

/**
 * The structure the model must answer a COA in: one forced tool call (`report_coa`). Every number is a string exactly as printed on the
 * document, and a value the model cannot read is null: the schema has no place for an estimate.
 */
final class CoaReportSchema
{
    public const TOOL = 'report_coa';

    /** The locale of the instruction sent to the model (as the assistant's system prompt: Egyptian Arabic whatever the interface language is). */
    public const LOCALE = 'ar';

    /** @return array{name: string, description: string, input_schema: array<string, mixed>} */
    public static function definition(): array
    {
        $nullableText = ['type' => ['string', 'null']];
        $propertyKeys = array_values(array_diff(PropertyRegistry::keys(), (array) config('coa.excluded_properties')));

        return [
            'name' => self::TOOL,
            'description' => (string) __('coa.prompt.tool_description', [], self::LOCALE),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'supplier' => $nullableText + ['description' => 'The supplier or manufacturer as printed, or null.'],
                    'lotNo' => $nullableText + ['description' => 'The batch / lot number as printed, or null.'],
                    'documentDate' => $nullableText + ['description' => 'The date of the certificate as YYYY-MM-DD, or null.'],
                    'fields' => [
                        'type' => 'array',
                        'maxItems' => (int) config('coa.max_fields'),
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'propertyAsPrinted' => ['type' => 'string', 'description' => 'The test name exactly as printed.'],
                                'propertyKey' => ['type' => ['string', 'null'], 'enum' => [...$propertyKeys, null], 'description' => 'The matching property key, or null when none matches.'],
                                'value' => $nullableText + ['description' => 'A single result, digits exactly as printed, or null.'],
                                'min' => $nullableText + ['description' => 'The lower limit of a printed range, digits as printed, or null.'],
                                'max' => $nullableText + ['description' => 'The upper limit of a printed range, digits as printed, or null.'],
                                'unit' => $nullableText + ['description' => 'The unit exactly as printed, or null.'],
                                'method' => $nullableText + ['description' => 'The test method as printed (a standard or a method name), or null.'],
                                'quote' => $nullableText + ['description' => 'The line of the document the result was read from, verbatim.'],
                                'page' => ['type' => ['integer', 'null']],
                                'readingConfidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            ],
                            'required' => ['propertyAsPrinted', 'propertyKey', 'value', 'min', 'max', 'unit', 'method', 'quote', 'readingConfidence'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['supplier', 'lotNo', 'documentDate', 'fields'],
                'additionalProperties' => false,
            ],
        ];
    }

    public static function system(): string
    {
        $prompt = (array) __('coa.prompt', [], self::LOCALE);

        return $prompt['intro']."\n\n".$prompt['rules_title']."\n".implode("\n", array_map(
            fn (string $rule, int $index): string => ($index + 1).'. '.$rule,
            $prompt['rules'],
            array_keys($prompt['rules']),
        ));
    }
}
