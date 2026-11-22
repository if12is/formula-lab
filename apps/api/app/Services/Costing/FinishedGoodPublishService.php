<?php

namespace App\Services\Costing;

use App\Actions\Costing\RecordFormulaPublicationAction;
use App\Actions\Sanad\PublishFinishedGoodToSanadAction;
use App\Exceptions\Domain\FinishedGoodPublishBlockedException;
use App\Exceptions\Domain\InvalidTaxSettingException;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Exceptions\Domain\SanadLinkException;
use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\FormulaPublication;
use App\Models\PriceListItem;
use App\Models\SanadDevice;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Tax\TaxRegimeService;
use App\Support\DecimalNormalizer;
use App\Support\Engine\EngineRunner;

/**
 * G-09: publishes a formula's finished good to Sanad, only when the owner-approved `publish-to-sanad` decision rule set allows it
 * (the cost is approved and still in force, the price inputs are complete, no material price moved past the configured threshold).
 * The rules are the engine's (scripts/publish-decision.ts); this class fills the context from the app's records, shows the answer in
 * words and pushes through the existing PublishFinishedGoodToSanadAction. The cost price sent is the APPROVED unit cost, never a
 * number typed in the publish form.
 */
final class FinishedGoodPublishService
{
    /** Details that must be present for the price waterfall to be complete; the tax setting is checked from the formula. */
    private const REQUIRED_DETAILS = ['sell_price'];

    public function __construct(
        private readonly EngineRunner $engine,
        private readonly CostApprovalService $approvals,
        private readonly CostReportEngineService $engineReport,
        private readonly TaxRegimeService $tax,
        private readonly PublishFinishedGoodToSanadAction $publishFinishedGood,
        private readonly RecordFormulaPublicationAction $recordPublication,
    ) {}

    /**
     * Everything the publish screen shows: the form's starting values, the cost approval state, the decision as it stands, the
     * wholesale price lists the product is on, and what was published before.
     *
     * @param  array<string, mixed>  $draft  Values typed in the form but not saved yet; the decision judges them instead of the stored ones.
     * @return array<string, mixed>
     */
    public function panel(Formula $formula, ?User $user, array $draft = []): array
    {
        $previous = FormulaPublication::query()->where('formula_id', $formula->getKey())->orderByDesc('published_at')->orderByDesc('id')->get();
        $last = $previous->first();
        $details = $this->formDefaults($formula, $last);
        $approval = $this->approvals->current($formula);
        $decision = $this->decide($formula, [...$details, ...array_filter($draft, fn (mixed $value): bool => is_string($value))], $user);

        return [
            'formula' => ['id' => $formula->getKey(), 'code' => $formula->code, 'name' => $formula->name, 'sanad_product_uuid' => $formula->sanad_product_uuid],
            'details' => $details,
            'approval' => $this->approvals->latest($formula) === null ? null : $this->approvalRow($this->approvals->latest($formula), $this->approvals->staleReason($formula)),
            'inForce' => $approval !== null,
            'tax' => ['set' => $formula->tax_mode !== null, 'label' => $this->taxLabel($formula)],
            'decision' => $decision,
            'priceLists' => $this->priceLists($formula),
            'history' => $previous->map(fn (FormulaPublication $publication): array => [
                'id' => $publication->getKey(),
                'published_at' => $publication->published_at->toIso8601String(),
                'mode' => $publication->mode,
                'sku' => $publication->details['sku'] ?? null,
                'cost_price' => $publication->details['cost_price'] ?? null,
                'sell_price' => $publication->details['sell_price'] ?? null,
                'decision_hash' => $publication->decision_hash,
            ])->all(),
        ];
    }

    /**
     * Publishes the finished good.
     *
     * @param  array{sku: string, barcode?: string|null, uom: string, sell_price: string, wholesale_price?: string|null, guide_price?: string|null, name_en?: string|null}  $details
     *
     * @throws FinishedGoodPublishBlockedException When the decision rules block the push, or cannot be judged.
     * @throws InvalidTaxSettingException When the tax setting is missing or has no regime in force.
     * @throws SanadLinkException When no Sanad link exists, or a price or the unit is invalid.
     * @throws SanadIntegrationException In LAN mode, when Sanad refuses the upsert.
     */
    public function publish(Formula $formula, array $details, ?User $user): FormulaPublication
    {
        $decision = $this->decide($formula, $details, $user);
        if ($decision['state'] === 'undecidable') {
            throw FinishedGoodPublishBlockedException::undecidable($decision['detail']);
        }
        if ($decision['state'] === 'blocked') {
            throw new FinishedGoodPublishBlockedException($decision['reasons']);
        }

        $approval = $this->approvals->current($formula);
        $device = SanadDevice::query()->where('tenant_id', $formula->tenant_id)->orderBy('created_at')->first();
        if ($device === null && ! $this->lanConfigured()) {
            throw SanadLinkException::noLink();
        }

        // The cost sent is the approved one.
        $sent = [...$details, 'cost_price' => DecimalNormalizer::normalize($approval->unit_cost)];
        $result = ($this->publishFinishedGood)($formula, $sent, $device);

        return ($this->recordPublication)($formula, $approval, $result, $sent, $decision['record'], $user?->getKey());
    }

    /**
     * The decision for the details as they are: `allowed`, `blocked` (with each failed rule in words) or `undecidable` (the engine
     * could not judge, e.g. a price that cannot be compared).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function decide(Formula $formula, array $details, ?User $user): array
    {
        $approval = $this->approvals->current($formula);
        $answer = $this->engine->run('publish-decision', [
            'subjectRef' => $formula->getKey(),
            'actor' => (string) ($user?->getKey() ?? config('decisions.anonymous_actor')),
            'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'cost' => array_filter(['approved' => $approval !== null, 'approvedUnitCost' => $approval === null ? null : DecimalNormalizer::normalize($approval->unit_cost)], fn (mixed $part): bool => $part !== null),
            'priceWaterfall' => ['missingProperties' => $this->waterfallGaps($formula, $details)],
            'repricing' => array_filter([
                'approved' => $approval === null ? null : array_map(fn (array $price): string => $price['egp_per_kg'], $approval->material_prices),
                'current' => $approval === null ? null : $this->currentPrices($approval),
                'thresholdPct' => config('decisions.reapproval_price_change_threshold_pct'),
            ], fn (mixed $part): bool => $part !== null),
        ]);

        if (($answer['ok'] ?? false) !== true) {
            return ['state' => 'undecidable', 'detail' => $this->describeMissing((array) ($answer['missing'] ?? []))];
        }

        $reasons = array_map(fn (array $failed): array => [
            'rule' => $failed['ruleId'],
            'message' => $this->message($failed),
            'remedy' => __('decisions.'.substr((string) $failed['remedyKey'], strlen('decision.'))),
        ], $answer['failed']);

        return [
            'state' => $answer['decision'] === 'blocked' ? 'blocked' : 'allowed',
            'reasons' => $reasons,
            'passed' => $answer['passed'],
            'max_change_pct' => $answer['maxChangePct'],
            'threshold_pct' => config('decisions.reapproval_price_change_threshold_pct'),
            'rule_set' => $answer['ruleSet'],
            'decision_hash' => $answer['record']['decisionHash'],
            'record' => $answer['record'],
        ];
    }

    /**
     * What the price waterfall still lacks, as property tokens the rule set counts. Only presence is checked here: no price is worked out.
     *
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    private function waterfallGaps(Formula $formula, array $details): array
    {
        $gaps = [];
        if ($formula->tax_mode === null) {
            $gaps[] = 'tax_setting';
        }
        foreach (self::REQUIRED_DETAILS as $field) {
            if (trim((string) ($details[$field] ?? '')) === '') {
                $gaps[] = $field;
            }
        }

        return $gaps;
    }

    /**
     * Today's EGP-per-kg price of each ingredient of the approved version, by the engine's cost report; a price that cannot be
     * costed now is left out, so the rule set reports it instead of treating it as unchanged.
     *
     * @return array<string, string>
     */
    private function currentPrices(FormulaCostApproval $approval): array
    {
        $run = $this->engineReport->run($approval->formulaVersion);

        return collect($run['report']['lines'])->filter(fn (array $line): bool => $line['priceEgpPerKg'] !== null)->mapWithKeys(fn (array $line): array => [$line['materialId'] => $line['priceEgpPerKg']])->all();
    }

    /**
     * @param  array<string, mixed>  $failed  One failed rule of the engine's answer.
     */
    private function message(array $failed): string
    {
        $params = (array) $failed['params'];
        if (isset($params['materialIds'])) {
            $ids = array_filter(explode(',', (string) $params['materialIds']));
            $names = TradeMaterial::query()->whereIn('id', $ids)->pluck('trade_name', 'id');
            $params['materialIds'] = implode(', ', array_map(fn (string $id): string => (string) ($names[$id] ?? $id), $ids));
        }
        if (isset($params['missingProperties'])) {
            $params['missingProperties'] = implode(', ', array_map(fn (string $token): string => __('sanad_publish.missing.'.$token), array_filter(explode(',', (string) $params['missingProperties']))));
        }

        return __('decisions.'.substr((string) $failed['messageKey'], strlen('decision.')), array_map('strval', $params));
    }

    /**
     * @param  list<array<string, mixed>>  $missing
     */
    private function describeMissing(array $missing): string
    {
        $names = TradeMaterial::query()->whereIn('id', array_filter(array_column($missing, 'materialId')))->pluck('trade_name', 'id');

        return implode('; ', array_map(function (array $gap) use ($names): string {
            $reason = (string) $gap['reason'];
            $text = __('sanad_publish.undecidable.'.$reason);

            return (isset($gap['materialId']) ? ($names[$gap['materialId']] ?? $gap['materialId']).': ' : '').($text === 'sanad_publish.undecidable.'.$reason ? $reason : $text);
        }, $missing));
    }

    /**
     * The form's starting values: what was published last time, otherwise the product code and the configured unit.
     *
     * @return array<string, string|null>
     */
    private function formDefaults(Formula $formula, ?FormulaPublication $last): array
    {
        $previous = $last?->details ?? [];

        return [
            'sku' => $previous['sku'] ?? $formula->code,
            'barcode' => $previous['barcode'] ?? null,
            'uom' => $previous['uom'] ?? config('sanad.finished_good.default_uom'),
            'sell_price' => $previous['sell_price'] ?? null,
            'wholesale_price' => $previous['wholesale_price'] ?? null,
            'guide_price' => $previous['guide_price'] ?? null,
            'name_en' => $previous['name_en'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function approvalRow(FormulaCostApproval $approval, ?string $staleReason): array
    {
        $approval->loadMissing('unitCostSource', 'approver', 'formulaVersion');

        return [
            'id' => $approval->getKey(),
            'unit_cost' => DecimalNormalizer::normalize($approval->unit_cost),
            'confidence' => $approval->unit_cost_confidence->value,
            'source_title' => $approval->unitCostSource->title,
            'version' => $approval->formulaVersion->version,
            'approved_at' => $approval->approved_at->toIso8601String(),
            'approved_by' => $approval->approver?->name,
            'comment' => $approval->comment,
            'stale_reason' => $staleReason,
        ];
    }

    private function taxLabel(Formula $formula): ?string
    {
        if ($formula->tax_mode === null) {
            return null;
        }

        $rate = $this->tax->rateOf($formula);

        return $rate === null ? __('tax.state.disabled') : $rate.'%';
    }

    /**
     * The price lists (per-merchant wholesale prices) this product is on, and whether each reached Sanad.
     *
     * @return list<array<string, mixed>>
     */
    private function priceLists(Formula $formula): array
    {
        return PriceListItem::query()->with('priceList')->where('formula_id', $formula->getKey())->get()
            ->map(fn (PriceListItem $item): array => [
                'id' => $item->priceList->getKey(),
                'name' => $item->priceList->name,
                'sync_status' => $item->priceList->sync_status->value,
                'href' => route('price-lists.show', $item->priceList, false),
            ])->values()->all();
    }

    private function lanConfigured(): bool
    {
        return (string) config('sanad.client.base_url') !== '' && (string) config('sanad.client.token') !== '';
    }
}
