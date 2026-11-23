<?php

namespace App\Actions\Coa;

use App\Enums\Coa\CoaExtractionStatus;
use App\Enums\Confidence;
use App\Enums\SourceKind;
use App\Exceptions\Domain\CoaException;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionEvent;
use App\Services\Material\PropertyValueService;
use App\Services\Material\SourceService;
use App\Support\DecimalNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * A person confirms an extraction: the fields they accepted (with the edits they made) become sourced property values of the material, at
 * L1 (the batch's own COA), with the lot as the reference and a COA source that points back at this extraction. The values go through the
 * material property service, the one write path of property values. The decision of every field is kept in the confirming event.
 */
final class ConfirmCoaExtractionAction
{
    public function __construct(
        private readonly SourceService $sources,
        private readonly PropertyValueService $propertyValues,
    ) {}

    /**
     * @param  array{lot_no: string, valid_from: string, supplier?: string|null}  $header
     * @param  list<array{id: string, decision: string, property?: string|null, value?: string|null, min?: string|null, max?: string|null, unit?: string|null}>  $decisions
     *
     * @throws CoaException When it was already decided or no field was accepted.
     */
    public function __invoke(CoaExtraction $extraction, array $header, array $decisions, ?int $userId): CoaExtractionEvent
    {
        return DB::transaction(function () use ($extraction, $header, $decisions, $userId): CoaExtractionEvent {
            CoaExtraction::query()->whereKey($extraction->getKey())->lockForUpdate()->firstOrFail();

            $status = $extraction->status();
            if ($status->isFinal()) {
                throw CoaException::alreadyDecided($status->value);
            }

            $accepted = array_values(array_filter($decisions, fn (array $decision): bool => $decision['decision'] === 'accept'));
            if ($accepted === []) {
                throw CoaException::nothingAccepted();
            }

            $extraction->loadMissing('material', 'fields');
            $original = $extraction->fields->keyBy('id');
            $supplier = $header['supplier'] ?? $extraction->supplier;

            $source = $this->sources->create([
                'tenant_id' => $extraction->material->tenant_id,
                'kind' => SourceKind::Coa->value,
                'title' => __('coa.source_title', ['lot' => $header['lot_no'], 'supplier' => $supplier ?? __('coa.supplier_unknown'), 'hash' => substr($extraction->file_sha256, 0, 8)]),
                'url' => route('coa.show', $extraction),
                'retrieved_at' => $header['valid_from'],
            ]);

            $propertyValueIds = [];
            $record = [];
            foreach ($decisions as $decision) {
                $field = $original[$decision['id']] ?? throw CoaException::fieldNotOfExtraction();
                $entry = ['field_id' => $field->getKey(), 'decision' => $decision['decision']];

                if ($decision['decision'] === 'accept') {
                    $data = [
                        'property' => (string) $decision['property'],
                        'value' => $decision['value'] ?? null,
                        'min' => $decision['min'] ?? null,
                        'max' => $decision['max'] ?? null,
                        'unit' => (string) $decision['unit'],
                    ];
                    $created = $this->propertyValues->add($extraction->material, [
                        ...$data,
                        'source_id' => $source->getKey(),
                        'confidence' => Confidence::L1CoaBatch->value,
                        'valid_from' => $header['valid_from'],
                        'lot_no' => $header['lot_no'],
                    ], $userId);
                    $propertyValueIds[] = $created->getKey();
                    $entry += $data + [
                        'property_value_id' => $created->getKey(),
                        'edited' => $this->edited($field->property_key, $field->value, $field->min, $field->max, $field->unit, $data),
                    ];
                }
                $record[] = $entry;
            }

            return CoaExtractionEvent::query()->create([
                'coa_extraction_id' => $extraction->getKey(),
                'status' => CoaExtractionStatus::Confirmed,
                'user_id' => $userId,
                'occurred_at' => now(),
                'decisions' => ['lot_no' => $header['lot_no'], 'valid_from' => $header['valid_from'], 'source_id' => $source->getKey(), 'fields' => $record],
                'property_value_ids' => $propertyValueIds,
            ]);
        });
    }

    /**
     * Whether the person changed anything of what was extracted.
     *
     * @param  array{property: string, value: string|null, min: string|null, max: string|null, unit: string}  $final
     */
    private function edited(?string $property, ?string $value, ?string $min, ?string $max, ?string $unit, array $final): bool
    {
        $same = fn (?string $a, ?string $b): bool => $a === null || $b === null ? $a === $b : DecimalNormalizer::normalize($a) === DecimalNormalizer::normalize($b);

        return $property !== $final['property'] || $unit !== $final['unit']
            || ! $same($value, $final['value']) || ! $same($min, $final['min']) || ! $same($max, $final['max']);
    }
}
