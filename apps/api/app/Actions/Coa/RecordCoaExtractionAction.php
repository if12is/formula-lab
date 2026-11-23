<?php

namespace App\Actions\Coa;

use App\Enums\Coa\CoaExtractionStatus;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionEvent;
use App\Models\CoaExtractionField;
use App\Models\TradeMaterial;
use Illuminate\Support\Facades\DB;

/** Writes one extraction, its fields and the opening `extracted` event in one transaction. Nothing it writes is a material property: that happens only on confirmation. */
final class RecordCoaExtractionAction
{
    /**
     * @param  array{path: string, name: string, mime: string, size: int, sha256: string}  $file
     * @param  array{supplier: string|null, lot_no: string|null, document_date: string|null, text_check: string, model: string, input_tokens: int, output_tokens: int, raw_answer: array<string, mixed>}  $header
     * @param  list<array<string, mixed>>  $fields  Each as CoaFieldChecker::check returns it.
     */
    public function __invoke(TradeMaterial $material, string $tenantId, array $file, array $header, array $fields, ?int $userId): CoaExtraction
    {
        return DB::transaction(function () use ($material, $tenantId, $file, $header, $fields, $userId): CoaExtraction {
            $extraction = CoaExtraction::query()->create([
                'tenant_id' => $tenantId,
                'trade_material_id' => $material->getKey(),
                'uploaded_by' => $userId,
                'file_path' => $file['path'],
                'file_name' => $file['name'],
                'file_mime' => $file['mime'],
                'file_size' => $file['size'],
                'file_sha256' => $file['sha256'],
                ...$header,
                'created_at' => now(),
            ]);

            foreach (array_values($fields) as $position => $field) {
                CoaExtractionField::query()->create([...$field, 'coa_extraction_id' => $extraction->getKey(), 'position' => $position + 1]);
            }

            CoaExtractionEvent::query()->create([
                'coa_extraction_id' => $extraction->getKey(),
                'status' => CoaExtractionStatus::Extracted,
                'user_id' => $userId,
                'occurred_at' => now(),
            ]);

            return $extraction;
        });
    }
}
