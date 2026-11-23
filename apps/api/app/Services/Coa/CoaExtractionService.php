<?php

namespace App\Services\Coa;

use App\Actions\Assistant\RecordAssistantRequestAction;
use App\Actions\Coa\ConfirmCoaExtractionAction;
use App\Actions\Coa\DiscardCoaFileAction;
use App\Actions\Coa\RecordCoaExtractionAction;
use App\Actions\Coa\RejectCoaExtractionAction;
use App\Actions\Coa\RunCoaExtractionAction;
use App\Actions\Coa\StoreCoaFileAction;
use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\Assistant\AssistantRequestStatus;
use App\Exceptions\Domain\AssistantException;
use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Models\CoaExtraction;
use App\Models\TradeMaterial;
use App\Support\Assistant\NumberGuard;
use App\Support\Assistant\TurnUsage;
use App\Support\Coa\CoaAnswer;
use App\Support\Coa\CoaDocumentText;
use App\Support\Coa\CoaFieldChecker;
use App\Support\Coa\DocumentTextReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The single write path of COA extractions (P-05). `extract` stores the file, has the model read it, checks every field in code and records
 * the draft (nothing reaches material properties); `confirm` and `reject` are the human decision, the only way a figure becomes a value.
 */
final class CoaExtractionService
{
    public function __construct(
        private readonly StoreCoaFileAction $storeFile,
        private readonly DiscardCoaFileAction $discardFile,
        private readonly RunCoaExtractionAction $runExtraction,
        private readonly RecordCoaExtractionAction $recordExtraction,
        private readonly ConfirmCoaExtractionAction $confirmExtraction,
        private readonly RejectCoaExtractionAction $rejectExtraction,
        private readonly RecordAssistantRequestAction $recordRequest,
        private readonly EnsureDefaultTenantAction $ensureDefaultTenant,
        private readonly DocumentTextReader $textReader,
        private readonly CoaFieldChecker $checker,
        private readonly NumberGuard $guard,
    ) {}

    /**
     * @throws BusinessRuleViolationException When the file cannot be read, the provider fails or its answer is not usable (nothing is kept then).
     */
    public function extract(UploadedFile $upload, TradeMaterial $material, ?int $userId = null): CoaExtraction
    {
        $file = ($this->storeFile)($upload);
        $usage = new TurnUsage;
        $started = hrtime(true);
        $tenantId = $material->tenant_id ?? ($this->ensureDefaultTenant)()->getKey();

        try {
            $document = CoaDocumentText::of($this->textReader->read(Storage::disk((string) config('coa.disk'))->path($file['path']), $file['mime']), $this->guard);
            $answer = ($this->runExtraction)($file['path'], $file['mime'], $usage);
            $fields = array_map(fn (array $raw): array => $this->checker->check($raw, $document), $answer->fields);

            $extraction = ($this->recordExtraction)($material, $tenantId, $file, $this->header($answer, $document, $usage), $fields, $userId);
        } catch (BusinessRuleViolationException $exception) {
            ($this->discardFile)($file['path']);
            $this->recordUsage(AssistantRequestStatus::Failed, $usage, $started, $userId, $tenantId, $exception instanceof AssistantException ? $exception->reason : 'coa_unusable');

            throw $exception;
        }

        $this->recordUsage(AssistantRequestStatus::Answered, $usage, $started, $userId, $tenantId);

        return $extraction;
    }

    /**
     * @param  array{lot_no: string, valid_from: string, supplier?: string|null}  $header
     * @param  list<array<string, mixed>>  $decisions
     */
    public function confirm(CoaExtraction $extraction, array $header, array $decisions, ?int $userId): void
    {
        ($this->confirmExtraction)($extraction, $header, $decisions, $userId);
    }

    public function reject(CoaExtraction $extraction, string $reason, ?int $userId): void
    {
        ($this->rejectExtraction)($extraction, $reason, $userId);
    }

    /** @return array{supplier: string|null, lot_no: string|null, document_date: string|null, text_check: string, model: string, input_tokens: int, output_tokens: int, raw_answer: array<string, mixed>} */
    private function header(CoaAnswer $answer, CoaDocumentText $document, TurnUsage $usage): array
    {
        // A supplier or lot the document text does not contain is not kept: the person types it.
        $inDocument = fn (?string $text): ?string => $text !== null && (! $document->checked() || $document->contains($text)) ? $text : null;

        return [
            'supplier' => $inDocument($answer->supplier),
            'lot_no' => $inDocument($answer->lotNo),
            'document_date' => $answer->documentDate,
            'text_check' => $document->checked() ? 'verified' : 'unverified',
            'model' => (string) config('assistant.model'),
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'raw_answer' => $answer->raw,
        ];
    }

    private function recordUsage(AssistantRequestStatus $status, TurnUsage $usage, int $started, ?int $userId, ?string $tenantId, ?string $errorCode = null): void
    {
        $record = ($this->recordRequest)($status, $usage, intdiv(hrtime(true) - $started, 1_000_000), $userId, $tenantId, $errorCode);

        Log::info('coa.extraction', ['request_id' => $record->getKey(), 'status' => $status->value, 'error' => $errorCode, 'input_tokens' => $usage->inputTokens, 'output_tokens' => $usage->outputTokens]);
    }
}
