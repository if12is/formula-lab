<?php

namespace App\Services\Costing;

use App\Enums\SourceKind;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\SanadPurchaseLine;
use App\Models\SanadSupplier;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Support\DecimalNormalizer;
use Illuminate\Support\Collection;

/**
 * I-08: the auditable cost report of one formula version, as the screen and the print view read it. Every number comes from the
 * engine's cost report (CostReportEngineService); this class only attaches where each number came from: the quote costed with, its
 * source (an invoice or a manual entry), its confidence and date, the quotes that lost and why, and the Sanad purchase invoices
 * on file for the material. A line with no usable price is listed as a gap, never shown as zero.
 */
final class CostReportService
{
    /** Purchase invoices of a material shown next to its price: the newest ones only. */
    private const INVOICES_PER_MATERIAL = 3;

    public function __construct(private readonly CostReportEngineService $engineReport) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Formula $formula, FormulaVersion $version): array
    {
        $run = $this->engineReport->run($version);

        return $this->present($formula, $version, $run['report'], $run['materials'], $run['quotes']);
    }

    /**
     * @param  array<string, mixed>  $report  The engine's cost report.
     * @param  array<string, TradeMaterial>  $materials
     * @param  array<string, list<array<string, mixed>>>  $quotes
     * @return array<string, mixed>
     */
    public function present(Formula $formula, FormulaVersion $version, array $report, array $materials, array $quotes): array
    {
        $sources = Source::query()->whereIn('id', collect($quotes)->flatten(1)->pluck('source_id')->unique()->all())->get()->keyBy('id');
        $invoices = $this->invoices($materials);

        $lines = collect($report['lines'])->map(function (array $line) use ($materials, $quotes, $sources, $invoices): array {
            $id = $line['materialId'];
            $material = $materials[$id];
            $options = $quotes[$id] ?? [];
            $chosen = $line['quoteIndex'] === null ? null : $this->quote($options[$line['quoteIndex']], $sources);

            return [
                'material_id' => $id,
                'material' => $material->trade_name,
                'percent_ww' => $line['percentWW'] === null ? null : DecimalNormalizer::normalize($line['percentWW']),
                'is_qs' => $line['qs'],
                'quote' => $chosen,
                'rejected' => array_map(fn (array $rejected): array => [
                    'quote' => $this->quote($options[$rejected['quoteIndex']], $sources),
                    'reason' => $rejected['reason'],
                    'detail' => $rejected['detail'],
                ], $line['rejected']),
                'price_egp_per_kg' => $line['priceEgpPerKg'],
                'contribution_egp_per_kg' => $line['contributionEgpPerKg'],
                'trace' => $line['trace'],
                'invoices' => $invoices[$id] ?? [],
            ];
        })->values()->all();

        return [
            'formula' => ['id' => $formula->getKey(), 'code' => $formula->code, 'name' => $formula->name],
            'version' => ['number' => $version->version, 'status' => $version->status->value, 'hash' => $version->hash],
            'ok' => $report['ok'],
            'lines' => $lines,
            'total' => $report['total'],
            'missing' => $this->gaps($report['missing'], $materials),
            'warnings' => array_map(fn (string $warning): string => $this->warning($warning), $report['warnings']),
            'engine' => [
                'version' => $report['engineVersion'],
                'dataset' => $report['datasetVersion'],
                'input_hash' => $report['inputHash'],
                'output_hash' => $report['outputHash'],
            ],
            // What a reader needs to open a leaf of the trace: a material by name, a source by title.
            'entities' => collect($materials)->mapWithKeys(fn (TradeMaterial $material, string $id): array => [$id => ['label' => $material->trade_name, 'href' => route('materials.show', ['material' => $id, 'tab' => 'prices'], false)]])->all(),
            'sources' => $this->sourceIndex($quotes, $sources),
            'unit_cost' => $this->unitCost($formula),
        ];
    }

    /**
     * One price quote with its source and where it came from: an invoice (a source of the invoice kind) or a manual entry.
     *
     * @param  array<string, mixed>  $quote  A price of MaterialOptionService::option.
     * @param  Collection<string, Source>  $sources
     * @return array<string, mixed>
     */
    private function quote(array $quote, Collection $sources): array
    {
        $source = $sources->get($quote['source_id']);

        return [
            'id' => $quote['id'],
            'amount' => DecimalNormalizer::normalize($quote['amount']),
            'currency' => $quote['currency'],
            'per_unit' => $quote['per_unit'],
            'quoted_on' => $quote['quoted_on'],
            'confidence' => $quote['confidence'],
            'source_id' => $quote['source_id'],
            'source_title' => $quote['source_title'],
            'source_kind' => $source?->kind,
            'source_url' => $source?->url,
            'origin' => $source?->kind === SourceKind::SupplierInvoice->value ? 'invoice' : 'manual',
            'fx' => $quote['fx'] === null ? null : [
                'rate' => DecimalNormalizer::normalize($quote['fx']['rate']),
                'rate_date' => $quote['fx']['rate_date'],
                'source_title' => $quote['fx']['source_title'],
            ],
        ];
    }

    /**
     * The unit cost kept on the product, with its source: the same two-way label (invoice or manual entry) as a price.
     *
     * @return array<string, mixed>|null
     */
    private function unitCost(Formula $formula): ?array
    {
        $formula->loadMissing('unitCostSource');

        if ($formula->unit_cost === null || $formula->unitCostSource === null || $formula->unit_cost_confidence === null) {
            return null;
        }

        return [
            'value' => DecimalNormalizer::normalize($formula->unit_cost),
            'source_id' => $formula->unitCostSource->getKey(),
            'source_title' => $formula->unitCostSource->title,
            'source_kind' => $formula->unitCostSource->kind,
            'source_url' => $formula->unitCostSource->url,
            'origin' => $formula->unitCostSource->kind === SourceKind::SupplierInvoice->value ? 'invoice' : 'manual',
            'confidence' => $formula->unit_cost_confidence->value,
            'retrieved_at' => $formula->unitCostSource->retrieved_at?->toDateString(),
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $quotes
     * @param  Collection<string, Source>  $sources
     * @return array<string, array{title: string|null, href: string|null}>
     */
    private function sourceIndex(array $quotes, Collection $sources): array
    {
        $index = [];
        foreach ($quotes as $materialId => $prices) {
            foreach ($prices as $price) {
                $index[$price['source_id']] = ['title' => $price['source_title'], 'href' => $sources->get($price['source_id'])?->url ?? route('materials.show', ['material' => $materialId, 'tab' => 'prices'], false)];
                if ($price['fx'] !== null) {
                    $index[$price['fx']['source_id']] = ['title' => $price['fx']['source_title'], 'href' => route('settings.exchange-rates.index', [], false)];
                }
            }
        }

        return $index;
    }

    /**
     * What each gap means in words, with where to fix it.
     *
     * @param  list<array<string, mixed>>  $missing
     * @param  array<string, TradeMaterial>  $materials
     * @return list<array<string, mixed>>
     */
    private function gaps(array $missing, array $materials): array
    {
        return array_map(function (array $gap) use ($materials): array {
            $id = $gap['materialId'] ?? null;
            $reason = (string) $gap['reason'];

            return [
                'material_id' => $id,
                'material' => $id === null ? null : ($materials[$id]->trade_name ?? null),
                'property' => $gap['property'],
                'reason' => __('engine.'.$reason) === 'engine.'.$reason ? $reason : __('engine.'.$reason),
                'href' => $id === null ? null : route('materials.show', ['material' => $id, 'tab' => 'prices'], false),
            ];
        }, $missing);
    }

    private function warning(string $warning): string
    {
        [$key] = explode(':', $warning, 2);

        return __('engine.'.$key) === 'engine.'.$key ? $warning : __('engine.'.$key);
    }

    /**
     * The newest Sanad purchase lines of each material's linked product: evidence next to the price, read from the cache.
     *
     * @param  array<string, TradeMaterial>  $materials
     * @return array<string, list<array<string, mixed>>>
     */
    private function invoices(array $materials): array
    {
        $uuids = collect($materials)->pluck('sanad_product_uuid')->filter()->unique()->values()->all();
        if ($uuids === []) {
            return [];
        }

        $lines = SanadPurchaseLine::query()->whereIn('sanad_product_uuid', $uuids)->orderByDesc('purchase_date')->orderBy('sanad_uuid')->get()->groupBy('sanad_product_uuid');
        $suppliers = SanadSupplier::query()->whereIn('uuid', $lines->flatten(1)->pluck('sanad_supplier_uuid')->filter()->unique()->all())->pluck('name', 'uuid');

        return collect($materials)->mapWithKeys(fn (TradeMaterial $material, string $id): array => [
            $id => $material->sanad_product_uuid === null ? [] : $lines->get($material->sanad_product_uuid, collect())->take(self::INVOICES_PER_MATERIAL)->map(fn (SanadPurchaseLine $line): array => [
                'purchase_uuid' => $line->sanad_purchase_uuid,
                'purchase_date' => $line->purchase_date->toDateString(),
                'status' => $line->purchase_status,
                'supplier' => $line->sanad_supplier_uuid === null ? null : ($suppliers[$line->sanad_supplier_uuid] ?? null),
                'unit_cost' => $line->unit_cost === null ? null : DecimalNormalizer::normalize($line->unit_cost),
                'currency' => $line->currency,
                'quantity' => $line->quantity === null ? null : DecimalNormalizer::normalize($line->quantity),
                'purchase_uom' => $line->purchase_uom,
            ])->values()->all(),
        ])->all();
    }
}
