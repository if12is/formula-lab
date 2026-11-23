<?php

namespace App\Actions\Production;

use App\Enums\FormulaStatus;
use App\Enums\Production\ProductionOrderStatus;
use App\Exceptions\Domain\ProductionException;
use App\Models\FormulaVersion;
use App\Models\ProductionOrder;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Support\Engine\ProductionEngine;
use App\Support\Production\ProductionSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Makes a production order from an APPROVED formula version and a batch size (Q-01). The order holds a snapshot of the version
 * (lines, steps, packaging) and its hash. An order the engine cannot weigh (a formula that does not add up, a volume batch) is refused here,
 * so a draft order is always one that can be weighed.
 */
final class CreateProductionOrderAction
{
    public function __construct(private readonly ProductionEngine $engine) {}

    /**
     * @param  array{batch_size: string, batch_unit: string, scale_resolution_g: string, units_count?: string|null, planned_on: string, expiry_date?: string|null, branch_code?: string|null, notes?: string|null, packaging?: list<array{trade_material_id: string, quantity: string}>}  $data
     *
     * @throws ProductionException When the version is not approved, its stored lines no longer match its hash, or the engine cannot weigh it.
     */
    public function __invoke(FormulaVersion $version, array $data, ?User $user = null): ProductionOrder
    {
        $version->loadMissing('formula');
        if ($version->status !== FormulaStatus::Approved) {
            throw ProductionException::versionNotApproved($version->formula->code, $version->version);
        }
        if (! $version->verifyHash()) {
            throw ProductionException::versionHashMismatch($version->formula->code, $version->version);
        }

        $names = TradeMaterial::query()->whereIn('id', array_column($data['packaging'] ?? [], 'trade_material_id'))->pluck('trade_name', 'id');
        $packaging = array_map(fn (array $line): array => [
            'trade_material_id' => $line['trade_material_id'],
            'material' => (string) $names[$line['trade_material_id']],
            'quantity' => $line['quantity'],
        ], $data['packaging'] ?? []);
        $snapshot = ProductionSnapshot::build($version, $packaging);

        $sheet = $this->engine->weighingSheet([
            'lines' => ProductionSnapshot::weighingLines($snapshot),
            'batchMass' => ['value' => $data['batch_size'], 'unit' => $data['batch_unit']],
            'resolutionG' => $data['scale_resolution_g'],
        ]);
        if (! $sheet['ok']) {
            throw ProductionException::notWeighable(array_map(fn (array $gap): string => $this->reason($gap), $sheet['missing'] ?? []));
        }

        return DB::transaction(function () use ($version, $data, $snapshot, $user): ProductionOrder {
            $tenantId = $version->formula->tenant_id;
            // Serialise concurrent orders of the tenant so the running numbers never collide.
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            $year = substr((string) $data['planned_on'], 0, 4);
            $orders = ProductionOrder::query()->where('tenant_id', $tenantId)->where('code', 'like', config('production.code_prefix')."-{$year}-%")->count();
            $day = str_replace('-', '', substr((string) $data['planned_on'], 2));
            $batches = ProductionOrder::query()->where('tenant_id', $tenantId)->where('batch_number', 'like', config('production.batch_prefix')."-{$day}-%")->count();

            return ProductionOrder::query()->create([
                'tenant_id' => $tenantId,
                'code' => sprintf('%s-%s-%04d', config('production.code_prefix'), $year, $orders + 1),
                'batch_number' => sprintf('%s-%s-%02d', config('production.batch_prefix'), $day, $batches + 1),
                'formula_version_id' => $version->getKey(),
                'formula_hash' => $version->hash,
                'snapshot' => $snapshot,
                'batch_size' => $data['batch_size'],
                'batch_unit' => $data['batch_unit'],
                'scale_resolution_g' => $data['scale_resolution_g'],
                'units_count' => $data['units_count'] ?? null,
                'planned_on' => $data['planned_on'],
                'expiry_date' => $data['expiry_date'] ?? null,
                'branch_code' => $data['branch_code'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => ProductionOrderStatus::Draft,
                'created_by' => $user?->getKey(),
            ]);
        });
    }

    /** @param  array<string, mixed>  $gap */
    private function reason(array $gap): string
    {
        $key = 'engine.'.$gap['reason'];

        return __($key) === $key ? (string) $gap['reason'] : __($key);
    }
}
