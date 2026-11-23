<?php

namespace App\Actions\Production;

use App\Models\ProductionOrder;
use App\Models\SanadDevice;
use App\Models\TradeMaterial;
use App\Services\Costing\CostApprovalService;
use App\Support\Engine\ProductionEngine;
use App\Support\Production\ProductionSnapshot;

/**
 * The `production_order` message Sanad pulls (sanad-contract-v1.md section 3.1) for an approved order: the finished good, the
 * quantity, what to consume (the planned masses of the engine's weighing sheet, in kg at six decimals, and the packaging) and what to
 * receive, with the approved unit cost. When something the message needs is missing, `payload` is null and `blockers` says what, in
 * words: nothing is guessed (a material that is not linked to a Sanad product is never dropped from the consumption).
 */
final class BuildProductionOrderPayloadAction
{
    public function __construct(
        private readonly ProductionEngine $engine,
        private readonly CostApprovalService $approvals,
    ) {}

    /** @return array{payload: array<string, mixed>|null, blockers: list<string>, device: SanadDevice|null} */
    public function __invoke(ProductionOrder $order): array
    {
        $order->loadMissing('formulaVersion.formula');
        $formula = $order->formulaVersion->formula;
        $snapshot = $order->snapshot;
        $blockers = [];

        $device = SanadDevice::query()->where('tenant_id', $order->tenant_id)->orderBy('created_at')->first();
        if ($device === null) {
            $blockers[] = __('production.blockers.no_device');
        }
        if ($formula->sanad_product_uuid === null) {
            $blockers[] = __('production.blockers.formula_not_published');
        }
        if ($order->units_count === null) {
            $blockers[] = __('production.blockers.units_missing');
        }
        $approval = $this->approvals->current($formula);
        if ($approval === null) {
            $blockers[] = __('production.blockers.cost_not_approved');
        }

        $sheet = $this->engine->weighingSheet([
            'lines' => ProductionSnapshot::weighingLines($snapshot),
            'batchMass' => ['value' => $order->batch_size, 'unit' => $order->batch_unit],
            'resolutionG' => $order->scale_resolution_g,
        ]);
        if (! $sheet['ok']) {
            return ['payload' => null, 'blockers' => [...$blockers, __('production.blockers.not_weighable')], 'device' => $device];
        }

        $materials = TradeMaterial::query()->whereIn('id', [...array_column($snapshot['lines'], 'trade_material_id'), ...array_column($snapshot['packaging'], 'trade_material_id')])->get()->keyBy('id');
        $names = array_column($snapshot['lines'], 'material', 'trade_material_id');
        foreach ($snapshot['lines'] as $line) {
            if ($materials[$line['trade_material_id']]->sanad_product_uuid === null) {
                $blockers[] = __('production.blockers.material_not_linked', ['material' => $line['material']]);
            }
        }
        foreach ($snapshot['packaging'] as $line) {
            if ($materials[$line['trade_material_id']]->sanad_product_uuid === null) {
                $blockers[] = __('production.blockers.material_not_linked', ['material' => $line['material']]);
            }
        }
        if ($blockers !== []) {
            return ['payload' => null, 'blockers' => array_values(array_unique($blockers)), 'device' => $device];
        }

        $decimals = ['units' => (string) $order->units_count, 'unit_cost' => (string) $approval->unit_cost];
        foreach ($snapshot['packaging'] as $index => $line) {
            $decimals["packaging.{$index}"] = $line['quantity'];
        }
        $contract = $this->engine->contractDecimals($decimals);

        $consume = array_map(fn (array $line): array => [
            'product_uuid' => $materials[$line['materialId']]->sanad_product_uuid,
            'quantity' => $line['consumeKg'],
            'uom' => config('production.sanad_uom.raw_material'),
            'kind' => 'raw_material',
        ], $sheet['value']['lines']);
        foreach ($snapshot['packaging'] as $index => $line) {
            $consume[] = [
                'product_uuid' => $materials[$line['trade_material_id']]->sanad_product_uuid,
                'quantity' => $contract["packaging.{$index}"],
                'uom' => config('production.sanad_uom.packaging'),
                'kind' => 'packaging',
            ];
        }

        return [
            'payload' => [
                'external_id' => $order->code,
                'formula_version' => ['formula_id' => $snapshot['formula']['id'], 'version' => $snapshot['formula']['version'], 'hash' => $order->formula_hash],
                'branch_code' => $order->branch_code,
                'planned_on' => $order->planned_on->toDateString(),
                'finished_good_uuid' => $formula->sanad_product_uuid,
                'batch_number' => $order->batch_number,
                'quantity' => $contract['units'],
                'uom' => config('production.sanad_uom.finished_good'),
                'production_date' => $order->planned_on->toDateString(),
                'expiry_date' => $order->expiry_date?->toDateString(),
                'consume' => $consume,
                'receive' => ['quantity' => $contract['units'], 'uom' => config('production.sanad_uom.finished_good'), 'unit_cost' => $contract['unit_cost']],
            ],
            'blockers' => [],
            'device' => $device,
        ];
    }
}
