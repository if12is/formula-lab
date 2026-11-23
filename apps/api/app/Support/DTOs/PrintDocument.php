<?php

namespace App\Support\DTOs;

use App\Enums\ExportKind;
use App\Enums\PrintWatermark;

/**
 * What a print preview needs to know about the document it wraps (S-17): which kind it is, what it is about, the hash printed on it
 * (the formula-version hash, or the content hash of a lot trace) and the watermark state. The export log is written from the same facts.
 */
final readonly class PrintDocument
{
    public function __construct(
        public ExportKind $kind,
        public ?string $subjectId,
        public string $subjectLabel,
        public string $hash,
        public PrintWatermark $watermark,
        public string $logUrl,
        public ?string $lot = null,
        public ?string $materialId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'subject_id' => $this->subjectId,
            'subject_label' => $this->subjectLabel,
            'hash' => $this->hash,
            'watermark' => $this->watermark->value,
            'lot' => $this->lot,
            'material_id' => $this->materialId,
            'log_url' => $this->logUrl,
        ];
    }
}
