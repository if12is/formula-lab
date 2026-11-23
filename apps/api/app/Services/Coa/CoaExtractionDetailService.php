<?php

namespace App\Services\Coa;

use App\Enums\Coa\CoaExtractionStatus;
use App\Enums\Coa\CoaFieldIssue;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionField;

/** Read side of one COA extraction: the review screen's props and the draft the assistant tool reports. Pure reads. */
final class CoaExtractionDetailService
{
    /**
     * @return array<string, mixed>
     */
    public function page(CoaExtraction $extraction): array
    {
        $extraction->load('material', 'uploader', 'events.user');
        $final = $extraction->events->first(fn ($event): bool => $event->status->isFinal());
        $decisions = collect($final?->decisions['fields'] ?? [])->keyBy('field_id');

        return [
            'extraction' => [
                'id' => $extraction->getKey(),
                'status' => $extraction->status()->value,
                'material' => ['id' => $extraction->material->getKey(), 'name' => $extraction->material->trade_name, 'href' => route('materials.show', ['material' => $extraction->material, 'tab' => 'properties'], false)],
                'file' => [
                    'name' => $extraction->file_name,
                    'size' => $extraction->file_size,
                    'sha256' => $extraction->file_sha256,
                    'kind' => $extraction->file_mime === 'application/pdf' ? 'pdf' : 'image',
                    'url' => route('coa.file', $extraction, false),
                ],
                'supplier' => $extraction->supplier,
                'lot_no' => $extraction->lot_no,
                'document_date' => $extraction->document_date?->toDateString(),
                'text_check' => $extraction->text_check,
                'model' => $extraction->model,
                'created_at' => $extraction->created_at->toIso8601String(),
                'uploaded_by' => $extraction->uploader?->name,
            ],
            'fields' => $extraction->fields->map(fn (CoaExtractionField $field): array => [
                ...$this->field($field),
                'decision' => $decisions->get($field->getKey()),
            ])->values()->all(),
            'events' => $extraction->events->map(fn ($event): array => [
                'status' => $event->status->value,
                'user' => $event->user?->name,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'reason' => $event->reason,
            ])->all(),
            'decided' => $final === null ? null : ['lot_no' => $final->decisions['lot_no'] ?? null, 'valid_from' => $final->decisions['valid_from'] ?? null],
            'urls' => [
                'index' => route('coa.index', [], false),
                'confirm' => route('coa.confirm', $extraction, false),
                'reject' => route('coa.reject', $extraction, false),
            ],
        ];
    }

    /**
     * One draft field as the screen and the assistant see it. The level is always L7 until a person confirms: an extracted figure is not data yet.
     *
     * @return array<string, mixed>
     */
    public function field(CoaExtractionField $field): array
    {
        return [
            'id' => $field->getKey(),
            'position' => $field->position,
            'property_as_printed' => $field->property_as_printed,
            'property_key' => $field->property_key,
            'value' => $field->value,
            'min' => $field->min,
            'max' => $field->max,
            'unit' => $field->unit,
            'as_printed' => $field->as_printed,
            'converted_from' => $field->converted_from,
            'method' => $field->method,
            'quote' => $field->quote,
            'page' => $field->page,
            'reading_confidence' => $field->reading_confidence->value,
            'level' => 'L7_estimate',
            'issues' => array_map(fn (string $code): array => ['code' => $code, 'blocking' => CoaFieldIssue::from($code)->blocking()], (array) $field->issues),
        ];
    }

    /**
     * The extraction as the assistant tool reports it: the draft, never as data.
     *
     * @return array<string, mixed>
     */
    public function draft(CoaExtraction $extraction): array
    {
        $extraction->loadMissing('material', 'fields');
        $status = $extraction->status();
        // What a person confirmed (with their corrections) is what is on file, not what the model first read.
        $accepted = $status === CoaExtractionStatus::Confirmed
            ? collect($extraction->events()->where('status', CoaExtractionStatus::Confirmed->value)->first()?->decisions['fields'] ?? [])->where('decision', 'accept')->keyBy('field_id')
            : collect();

        return [
            'extractionId' => $extraction->getKey(),
            'status' => $status->value,
            'confirmed' => $status === CoaExtractionStatus::Confirmed,
            'material' => $extraction->material->trade_name,
            'supplier' => $extraction->supplier,
            'lotNo' => $extraction->lot_no,
            'textCheck' => $extraction->text_check,
            'fields' => $extraction->fields
                ->filter(fn (CoaExtractionField $field): bool => $status !== CoaExtractionStatus::Confirmed || $accepted->has($field->getKey()))
                ->map(function (CoaExtractionField $field) use ($status, $accepted): array {
                    $row = $this->field($field);
                    $final = $accepted->get($field->getKey());

                    return [
                        'property' => $final['property'] ?? $row['property_key'] ?? $row['property_as_printed'],
                        'value' => $final['value'] ?? $row['value'],
                        'min' => $final['min'] ?? $row['min'],
                        'max' => $final['max'] ?? $row['max'],
                        'unit' => $final['unit'] ?? $row['unit'],
                        'method' => $row['method'],
                        'readingConfidence' => $row['reading_confidence'],
                        'issues' => array_column($row['issues'], 'code'),
                        // Only a confirmed extraction has values on file (L1); a draft is an unconfirmed reading (L7) that no calculation reads.
                        'confidence' => $status === CoaExtractionStatus::Confirmed ? 'L1_coa_batch' : $row['level'],
                    ];
                })->values()->all(),
        ];
    }
}
