<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Enums\Coa\CoaExtractionStatus;
use App\Models\CoaExtraction;
use App\Services\Coa\CoaExtractionDetailService;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;

/**
 * Reports a COA the model has already read (P-05): the draft, field by field, with what the checks found and the review state. The reading itself
 * is made on upload (CoaExtractionService), where the document is, and a person confirms it on the review screen: this tool never saves, confirms or
 * reads a file, and a draft is reported as an unconfirmed reading (L7) that nothing calculates with.
 */
final class ExtractCoaTool implements AssistantTool
{
    use BuildsToolAnswers;

    public function __construct(private readonly CoaExtractionDetailService $details) {}

    public function name(): string
    {
        return 'extract_coa';
    }

    public function description(): string
    {
        return 'Report the draft of a certificate of analysis (COA) that was uploaded and read, by extractionId. The values are an UNCONFIRMED reading (L7): a person must confirm each one on the review screen before it is saved (then L1) and before any calculation can use it. Never present a draft value as data. missing lists what the document did not give.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'extractionId' => ['type' => 'string', 'description' => 'The id of the uploaded and read COA.'],
            ],
            'required' => ['extractionId'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::SupplierCertificate];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, ['extractionId' => ['required', 'uuid']]);
        $extraction = CoaExtraction::query()->find($valid['extractionId']);

        if ($extraction === null) {
            return $this->missing([['property' => 'extractionId', 'reason' => 'coa.not_found']]);
        }

        $draft = $this->details->draft($extraction);
        $confirmed = $extraction->status() === CoaExtractionStatus::Confirmed;
        $missing = array_values(array_filter([
            $extraction->lot_no === null && ! $confirmed ? ['property' => 'lot_no', 'reason' => 'coa.lot_not_read'] : null,
            ...array_map(
                fn (array $field): array => ['property' => (string) $field['property'], 'reason' => 'coa.value_not_read'],
                array_values(array_filter($draft['fields'], fn (array $field): bool => $field['value'] === null && $field['min'] === null && $field['max'] === null)),
            ),
        ]));

        $warnings = $confirmed ? [] : ['coa.draft_unconfirmed'];

        // The answer is ok either way: a draft exists. `missing` says what the document did not give, `warnings` that nothing is confirmed.
        return ['ok' => true, 'data' => $draft, 'missing' => $missing, 'warnings' => $warnings];
    }
}
