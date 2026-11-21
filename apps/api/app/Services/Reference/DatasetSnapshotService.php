<?php

namespace App\Services\Reference;

use App\Models\DatasetVersion;
use App\Support\CanonicalJson;
use App\Support\DecimalNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the canonical reference-data snapshot and publishes it as an immutable dataset version.
 * Snapshot id = SHA-256 of its canonical JSON, identical to what the TS engine computes (hash.ts).
 * Timestamps and author ids are excluded: they do not change the data, so re-importing the same
 * data yields the same hash.
 */
class DatasetSnapshotService
{
    public const SCHEMA = 'fl.dataset/1';

    /** @var array<string, array{columns: list<string>, decimals: list<string>}> */
    private const TABLES = [
        'sources' => [
            'columns' => ['id', 'kind', 'title', 'url', 'license', 'retrieved_at'],
            'decimals' => [],
        ],
        'property_values' => [
            'columns' => ['id', 'tenant_id', 'entity_type', 'entity_id', 'property', 'value', 'min', 'max',
                'unit', 'source_id', 'confidence', 'valid_from', 'lot_no'],
            'decimals' => ['value', 'min', 'max'],
        ],
        'prices' => [
            'columns' => ['id', 'tenant_id', 'trade_material_id', 'sanad_supplier_uuid', 'amount', 'currency',
                'per_unit', 'quoted_on', 'fx_rate', 'fx_source', 'source_id', 'confidence'],
            'decimals' => ['amount', 'fx_rate'],
        ],
    ];

    /**
     * Snapshot of the global reference rows (tenant_id null) plus, when given, one tenant's rows.
     * Rows are sorted by id; decimals are normalized strings; keys are sorted by the canonical encoder.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?string $tenantId = null): array
    {
        $snapshot = ['schema' => self::SCHEMA, 'tenantId' => $tenantId];

        foreach (self::TABLES as $table => $definition) {
            $snapshot[$table] = $this->rows($table, $definition, $tenantId);
        }

        return $snapshot;
    }

    /** @param  array<string, mixed>  $snapshot */
    public function hash(array $snapshot): string
    {
        return CanonicalJson::hash($snapshot);
    }

    /** Publishes (idempotently) the current snapshot; an unchanged dataset returns the existing version. */
    public function publish(?string $tenantId = null, ?string $notes = null): DatasetVersion
    {
        $snapshot = $this->snapshot($tenantId);
        $hash = $this->hash($snapshot);

        return DatasetVersion::query()->find($hash) ?? DatasetVersion::create([
            'id' => $hash,
            'notes' => $notes,
            'manifest' => [
                'schema' => self::SCHEMA,
                'tenantId' => $tenantId,
                'counts' => array_map('count', array_intersect_key($snapshot, self::TABLES)),
            ],
        ]);
    }

    /**
     * @param  array{columns: list<string>, decimals: list<string>}  $definition
     * @return list<array<string, string|null>>
     */
    private function rows(string $table, array $definition, ?string $tenantId): array
    {
        return DB::table($table)
            ->select($definition['columns'])
            ->where(fn (Builder $scope) => $scope->whereNull('tenant_id')
                ->when($tenantId !== null, fn (Builder $q) => $q->orWhere('tenant_id', $tenantId)))
            ->orderBy('id')
            ->get()
            ->map(function (object $row) use ($definition): array {
                $values = (array) $row;
                foreach ($definition['decimals'] as $column) {
                    $values[$column] = $values[$column] === null ? null : DecimalNormalizer::normalize($values[$column]);
                }

                return array_map(fn ($v) => $v === null ? null : (string) $v, $values);
            })
            ->all();
    }
}
