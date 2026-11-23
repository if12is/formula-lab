<?php

namespace App\Services\Dashboard;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\FieldGroup;
use App\Enums\FormulaStatus;
use App\Enums\Production\DeviationApprovalStatus;
use App\Enums\Production\ProductionOrderStatus;
use App\Enums\RuleSetStatus;
use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\FormulaPublication;
use App\Models\FormulaVersion;
use App\Models\Price;
use App\Models\ProductionDeviation;
use App\Models\ProductionOrder;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Compliance\ComplianceEditorService;
use App\Services\Costing\CostApprovalService;
use App\Services\Costing\CostInputService;
use App\Services\Material\MaterialOptionService;
use App\Services\Security\FieldVisibilityService;
use App\Support\DecimalCompare;
use App\Support\DecimalNormalizer;
use App\Support\Engine\EngineRunner;
use App\Support\Engine\GapText;
use Illuminate\Support\Collection;

/**
 * S-15: the decision figures of the home page. This class gathers facts from the app's records (the newest price date of each material in use,
 * each product's cost-report input, what its cost was approved at, its published price, its compliance input) and hands them to the engine in
 * one call (`scripts/dashboard-kpis.ts`); it then counts and orders what the engine says. A figure the engine cannot work out arrives as
 * `missing` with its reason and is shown as such, never as 0. The pending-approval figures are plain counts of records waiting for a person.
 */
final class DashboardService
{
    public function __construct(
        private readonly EnsureDefaultTenantAction $ensureDefaultTenant,
        private readonly EngineRunner $engine,
        private readonly CostInputService $costInputs,
        private readonly CostApprovalService $approvals,
        private readonly MaterialOptionService $materialOptions,
        private readonly ComplianceEditorService $ruleSets,
        private readonly FieldVisibilityService $visibility,
    ) {}

    /**
     * The figures built on what things cost (stale prices, the price-move impact, costs waiting for approval) are left out, not just hidden,
     * for a user whose role does not see the cost group (U-07): the screen reads their absence and draws no card.
     *
     * @return array<string, mixed>
     */
    public function kpis(?User $user = null): array
    {
        $seesCost = $this->visibility->allows($user, FieldGroup::Cost);
        $tenantId = ($this->ensureDefaultTenant)()->getKey();
        $formulas = Formula::query()->where('tenant_id', $tenantId)->orderBy('code')->get();
        $limit = (int) config('dashboard.list_limit');

        // Per product: the version the figures are about (the one its cost was approved on, else the newest) and what it was approved at.
        $approvalOf = $formulas->mapWithKeys(fn (Formula $formula): array => [$formula->getKey() => $this->approvals->latest($formula)])->filter();
        $versionOf = $formulas->mapWithKeys(fn (Formula $formula): array => [$formula->getKey() => $this->versionFor($formula, $approvalOf->get($formula->getKey()))])->filter();
        $versions = $versionOf->values()->unique(fn (FormulaVersion $version): string => $version->getKey())->values();
        $inputs = $this->costInputs->forVersions($versions);

        $materials = collect($inputs)->flatMap(fn (array $entry): array => $entry['materials'])->unique(fn (TradeMaterial $material): string => $material->getKey());
        $options = $this->materialOptions->forMaterials($materials->values());
        $names = $materials->mapWithKeys(fn (TradeMaterial $material): array => [$material->getKey() => $material->trade_name])->all();
        $newest = Price::query()->selectRaw('trade_material_id, max(quoted_on) as newest')->whereIn('trade_material_id', $materials->keys()->all())->groupBy('trade_material_id')->pluck('newest', 'trade_material_id');

        $published = $this->rulesFor();
        $facts = $formulas->filter(fn (Formula $formula): bool => $versionOf->has($formula->getKey()))->map(function (Formula $formula) use ($versionOf, $approvalOf, $inputs, $options, $published): array {
            $version = $versionOf->get($formula->getKey());
            $approval = $approvalOf->get($formula->getKey());
            $entry = $inputs[$version->getKey()];

            return array_filter([
                'id' => $formula->getKey(),
                'costReport' => $entry['input'],
                'approved' => $approval === null ? null : [
                    'materialPrices' => collect($approval->material_prices)->map(fn (array $price): string => DecimalNormalizer::normalize((string) $price['egp_per_kg']))->all(),
                    'unitCost' => DecimalNormalizer::normalize($approval->unit_cost),
                ],
                'sellPrice' => $this->sellPrice($formula),
                'compliance' => $this->complianceFacts($formula, $entry['materials'], $options, $published),
            ], fn (mixed $part): bool => $part !== null);
        })->values();

        $answer = $this->engine->run('dashboard-kpis', [
            'asOf' => now()->toDateString(),
            'stalePriceDays' => config('dashboard.stale_price_days'),
            'materials' => $materials->values()->map(fn (TradeMaterial $material): array => ['materialId' => $material->getKey(), 'latestQuotedOn' => $newest->has($material->getKey()) ? substr((string) $newest->get($material->getKey()), 0, 10) : null])->all(),
            'formulas' => $facts->all(),
        ]);
        $byFormula = collect($answer['formulas'])->keyBy('id');

        return [
            'as_of' => now()->toDateString(),
            'formula_count' => $formulas->count(),
            'stale' => $seesCost ? $this->stale($answer['stale'], $names, $limit) : null,
            'quality' => $this->quality($formulas, $byFormula, $names, $limit),
            'compliance' => $this->compliance($formulas, $byFormula, $limit),
            'approvals' => $this->pendingApprovals($formulas, $limit, $seesCost),
            'impact' => $seesCost ? $this->impact($formulas, $byFormula, $limit) : null,
        ];
    }

    private function versionFor(Formula $formula, ?FormulaCostApproval $approval): ?FormulaVersion
    {
        if ($approval !== null) {
            return $approval->formulaVersion;
        }

        return $formula->versions()->reorder()->orderByDesc('version')->first();
    }

    private function sellPrice(Formula $formula): ?string
    {
        $price = FormulaPublication::query()->where('formula_id', $formula->getKey())->orderByDesc('published_at')->orderByDesc('id')->first()?->details['sell_price'] ?? null;

        return is_string($price) && DecimalNormalizer::isValid($price) ? DecimalNormalizer::normalize($price) : null;
    }

    /**
     * The published rule sets, assembled, with the product types each one names.
     *
     * @return list<array<string, mixed>>
     */
    private function rulesFor(): array
    {
        return array_values(array_filter($this->ruleSets->ruleSets(), fn (array $ruleSet): bool => $ruleSet['status'] === RuleSetStatus::Published->value));
    }

    /**
     * @param  array<string, TradeMaterial>  $materials
     * @param  array<string, array<string, mixed>>  $options  MaterialOptionService options by material id.
     * @param  list<array<string, mixed>>  $ruleSets
     * @return array<string, mixed>
     */
    private function complianceFacts(Formula $formula, array $materials, array $options, array $ruleSets): array
    {
        $fragrances = array_values(array_filter(array_keys($materials), fn (string $id): bool => (bool) ($options[$id]['is_fragrance'] ?? false)));

        return array_filter([
            'materials' => array_values(array_map(fn (string $id): array => array_filter(['materialId' => $id, 'composition' => $options[$id]['composition'] ?? null], fn (mixed $part): bool => $part !== null), array_keys($materials))),
            'ruleSets' => array_values(array_map(fn (array $ruleSet): array => $ruleSet['assembled'], array_filter($ruleSets, fn (array $ruleSet): bool => in_array($formula->product_type, $ruleSet['productTypes'], true)))),
            'context' => array_filter(['productType' => $formula->product_type, 'ifraCategory' => $formula->ifra_category ?: null], fn (mixed $part): bool => $part !== null),
            'ifra' => $fragrances === [] ? null : array_filter([
                'category' => $formula->ifra_category ?: null,
                'fragrances' => array_map(fn (string $id): array => ['materialId' => $id, 'limits' => array_map(fn (array $row): array => [
                    'id' => $row['id'], 'category' => $row['category'], 'value' => $row['value'], 'unit' => $row['unit'],
                    'sourceId' => $row['source_id'], 'confidence' => $row['confidence'], 'validFrom' => $row['valid_from'],
                ], $options[$id]['ifra_limits'] ?? [])], $fragrances),
            ], fn (mixed $part): bool => $part !== null),
        ], fn (mixed $part): bool => $part !== null);
    }

    /**
     * @param  array<string, mixed>  $result  The engine's Result of the stale-price check.
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function stale(array $result, array $names, int $limit): array
    {
        if (! $result['ok']) {
            return ['value' => null, 'checked' => null, 'items' => [], 'unpriced' => [], 'missing' => GapText::all($result['missing'], [], 'dashboard.fields')];
        }
        $value = $result['value'];

        return [
            'value' => $value['staleCount'],
            'checked' => $value['checkedCount'],
            'items' => array_map(fn (array $row): array => ['material' => $names[$row['materialId']] ?? $row['materialId'], 'href' => route('materials.show', ['material' => $row['materialId'], 'tab' => 'prices'], false), 'quoted_on' => $row['quotedOn'], 'age_days' => $row['ageDays']], array_slice($value['stale'], 0, $limit)),
            'unpriced' => array_map(fn (string $id): string => $names[$id] ?? $id, $value['unpriced']),
            'missing' => [],
        ];
    }

    /**
     * @param  Collection<int, Formula>  $formulas
     * @param  Collection<string, array<string, mixed>>  $byFormula
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function quality(Collection $formulas, Collection $byFormula, array $names, int $limit): array
    {
        $rows = $formulas->filter(fn (Formula $formula): bool => $byFormula->has($formula->getKey()))->map(function (Formula $formula) use ($byFormula, $names): array {
            $quality = $byFormula->get($formula->getKey())['quality'];

            return [
                'formula' => $formula,
                'missing' => GapText::all($quality['missing'], $names, 'dashboard.fields'),
                'low' => array_map(fn (array $row): array => ['material' => $names[$row['materialId']] ?? $row['materialId'], 'confidence' => $row['confidence']], $quality['lowConfidence']),
            ];
        })->values();
        $flagged = $rows->filter(fn (array $row): bool => $row['missing'] !== [] || $row['low'] !== [])->values();

        return [
            'value' => $byFormula->isEmpty() ? null : (string) $flagged->count(),
            'checked' => (string) $rows->count(),
            'items' => $flagged->take($limit)->map(fn (array $row): array => [
                'formula' => $this->formulaRow($row['formula']),
                'missing' => $row['missing'],
                'low' => $row['low'],
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, Formula>  $formulas
     * @param  Collection<string, array<string, mixed>>  $byFormula
     * @return array<string, mixed>
     */
    private function compliance(Collection $formulas, Collection $byFormula, int $limit): array
    {
        $rows = $formulas->filter(fn (Formula $formula): bool => ($byFormula->get($formula->getKey())['compliance'] ?? null) !== null)->map(fn (Formula $formula): array => [
            'formula' => $formula,
            'result' => $byFormula->get($formula->getKey())['compliance'],
        ])->values();
        $count = fn (string $status): string => (string) $rows->filter(fn (array $row): bool => $row['result']['overall'] === $status)->count();
        $flagged = $rows->filter(fn (array $row): bool => in_array($row['result']['overall'], ['FAIL', 'UNKNOWN'], true))
            ->sortBy(fn (array $row): string => ($row['result']['overall'] === 'FAIL' ? '0' : '1').$row['formula']->code)->values();

        return [
            'value' => $rows->isEmpty() ? null : (string) $flagged->count(),
            'checked' => (string) $rows->count(),
            'fail' => $count('FAIL'),
            'unknown' => $count('UNKNOWN'),
            'pass' => $count('PASS'),
            'not_applicable' => $count('NOT_APPLICABLE'),
            'items' => $flagged->take($limit)->map(fn (array $row): array => [
                'formula' => $this->formulaRow($row['formula']),
                'status' => $row['result']['overall'],
                'failed' => count($row['result']['failedRuleIds']),
                'unknown' => count($row['result']['unknownRuleIds']) + count($row['result']['invalidRuleSets']),
            ])->all(),
        ];
    }

    /**
     * Records waiting for a person to decide. These are counts of records, not calculations.
     *
     * @param  Collection<int, Formula>  $formulas
     * @return array<string, mixed>
     */
    private function pendingApprovals(Collection $formulas, int $limit, bool $seesCost): array
    {
        $tenantId = $formulas->first()?->tenant_id;
        $inLab = FormulaVersion::query()->with('formula')->whereIn('formula_id', $formulas->pluck('id'))->where('status', FormulaStatus::LabTesting)->get();
        $orders = ProductionOrder::query()->where('status', ProductionOrderStatus::Draft)->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->get();
        $deviations = ProductionDeviation::query()->where('approval_status', DeviationApprovalStatus::Pending)->count();
        $costs = ! $seesCost ? collect() : $formulas->filter(fn (Formula $formula): bool => $this->approvals->latestApprovedVersion($formula) !== null && $this->approvals->staleReason($formula) !== null)->values();

        $total = $inLab->count() + $orders->count() + $deviations + $costs->count();

        return [
            'value' => (string) $total,
            'versions_in_lab' => (string) $inLab->count(),
            'orders_to_approve' => (string) $orders->count(),
            'deviations_pending' => (string) $deviations,
            ...($seesCost ? ['costs_to_approve' => (string) $costs->count()] : []),
            'items' => [
                ...$inLab->take($limit)->map(fn (FormulaVersion $version): array => ['kind' => 'version', 'label' => $version->formula->code.' · v'.$version->version, 'href' => route('formulas.show', $version->formula, false)])->all(),
                ...$costs->take($limit)->map(fn (Formula $formula): array => ['kind' => 'cost', 'label' => $formula->code, 'href' => route('formulas.cost-report', $formula, false)])->all(),
            ],
        ];
    }

    /**
     * What the material price moves since each product's cost was approved did to its material cost, and the most it can do to the margin.
     *
     * @param  Collection<int, Formula>  $formulas
     * @param  Collection<string, array<string, mixed>>  $byFormula
     * @return array<string, mixed>
     */
    private function impact(Collection $formulas, Collection $byFormula, int $limit): array
    {
        $rows = $formulas->filter(fn (Formula $formula): bool => ($byFormula->get($formula->getKey())['impact'] ?? null) !== null)->map(function (Formula $formula) use ($byFormula): array {
            $entry = $byFormula->get($formula->getKey());
            $impact = $entry['impact'];

            return [
                'formula' => $formula,
                'impact' => $impact['ok'] ? $impact['value'] : null,
                'impact_missing' => $impact['ok'] ? [] : GapText::all($impact['missing'], [], 'dashboard.fields'),
                'margin' => $entry['margin'] !== null && $entry['margin']['ok'] ? $entry['margin']['value'] : null,
                'margin_missing' => $entry['margin'] !== null && ! $entry['margin']['ok'] ? GapText::all($entry['margin']['missing'], [], 'dashboard.fields') : [],
            ];
        })->values();
        $measured = $rows->filter(fn (array $row): bool => $row['impact'] !== null && $row['impact']['deltaPct'] !== null)->values();
        $largest = $measured->reduce(fn (?array $best, array $row): array => $best === null || DecimalCompare::compare(ltrim($row['impact']['deltaPct'], '-'), ltrim($best['impact']['deltaPct'], '-')) > 0 ? $row : $best);

        return [
            'value' => $largest === null ? null : $largest['impact']['deltaPct'],
            'checked' => (string) $rows->count(),
            'largest' => $largest === null ? null : $this->formulaRow($largest['formula']),
            'threshold_pct' => config('decisions.reapproval_price_change_threshold_pct'),
            'items' => $rows->sort(fn (array $a, array $b): int => DecimalCompare::compare(ltrim($b['impact']['deltaPct'] ?? '0', '-'), ltrim($a['impact']['deltaPct'] ?? '0', '-')))->take($limit)->map(fn (array $row): array => [
                'formula' => $this->formulaRow($row['formula']),
                'impact' => $row['impact'],
                'impact_missing' => $row['impact_missing'],
                'margin' => $row['margin'],
                'margin_missing' => $row['margin_missing'],
            ])->values()->all(),
        ];
    }

    /** @return array<string, string> */
    private function formulaRow(Formula $formula): array
    {
        return ['id' => $formula->getKey(), 'code' => $formula->code, 'name' => $formula->name, 'href' => route('formulas.show', $formula, false)];
    }
}
