<?php

namespace App\Services\Formula;

use App\Actions\Formula\RecordFormulaBalanceAction;
use App\Enums\FormulaStatus;
use App\Enums\ProductTier;
use App\Exceptions\Domain\InvalidFormulaVersionException;
use App\Models\DatasetVersion;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Compliance\ComplianceEditorService;
use App\Services\Material\MaterialOptionService;
use App\Services\Tax\TaxRegimeService;
use Illuminate\Support\Facades\DB;

/** Everything the formula editor page needs, and the save of an edited version as a new version row. */
final class FormulaEditorService
{
    public function __construct(
        private FormulaVersionService $formulaVersionService,
        private MaterialOptionService $materialOptionService,
        private FormulaHistoryService $formulaHistoryService,
        private RecordFormulaBalanceAction $recordFormulaBalance,
        private ComplianceEditorService $complianceEditorService,
        private TaxRegimeService $taxRegimeService,
    ) {}

    /**
     * Props of an empty editor (a new formula).
     *
     * @return array<string, mixed>
     */
    public function blank(): array
    {
        return [...$this->shared(), 'formula' => null, 'version' => null, 'lines' => [], 'materials' => (object) [], 'history' => []];
    }

    /**
     * Props of the editor for one version of an existing formula.
     *
     * @return array<string, mixed>
     */
    public function forVersion(Formula $formula, FormulaVersion $version): array
    {
        $latest = (int) $formula->versions()->reorder()->max('version');

        return [
            ...$this->shared(),
            'formula' => [
                'id' => $formula->getKey(),
                'code' => $formula->code,
                'name' => $formula->name,
                'product_type' => $formula->product_type,
                'tier' => $formula->tier?->value,
                'ifra_category' => $formula->ifra_category,
            ],
            'version' => [
                'number' => $version->version,
                'status' => $version->status->value,
                'reason' => $version->reason,
                'hash' => $version->hash,
                'created_at' => $version->created_at?->toIso8601String(),
                'is_latest' => $version->version === $latest,
                // Approved versions are immutable; an older version is a base for a new one, never edited in place.
                'editable' => $version->status !== FormulaStatus::Approved && $version->version === $latest,
            ],
            ...$this->linesAndMaterials($version),
            'history' => $this->formulaHistoryService->history($formula, $version),
            // The rule sets the compliance panel hands to the engine (it runs in the browser, on the draft being edited).
            'compliance' => ['ruleSets' => $this->complianceEditorService->ruleSets()],
            // The product's tax setting and the dated regimes the engine checks it against (pricing panel).
            'tax' => $this->taxRegimeService->editorProps($formula),
        ];
    }

    /**
     * The lines of a version as the editor and the label page read them, with the materials they use (prices, composition, IFRA limits).
     *
     * @return array{lines: list<array<string, mixed>>, materials: object}
     */
    public function linesAndMaterials(FormulaVersion $version): array
    {
        $version->load('lines');
        $materials = TradeMaterial::query()->with('substance')->whereIn('id', $version->lines->pluck('trade_material_id'))->get();

        return [
            'lines' => $version->lines->map(fn (FormulaLine $line): array => [
                'trade_material_id' => $line->trade_material_id,
                'percent_ww' => $line->percent_ww === null ? '' : $this->plain($line->percent_ww),
                'is_qs' => $line->is_qs,
                'phase' => $line->phase ?? '',
            ])->all(),
            'materials' => (object) $this->materialOptionService->forMaterials($materials),
        ];
    }

    /**
     * Saves the edited lines as the next version of the formula, with the reason and the q.s. balancing that led to it.
     *
     * @param  list<array{trade_material_id: string, percent_ww?: string|null, is_qs?: bool, phase?: string|null}>  $lines
     * @param  list<array{material_id: string, before?: string|null, after: string}>  $balanceEvents
     *
     * @throws InvalidFormulaVersionException When a reason is missing or there are no lines.
     */
    public function saveVersion(Formula $formula, FormulaVersion $base, array $lines, string $reason, array $balanceEvents, ?User $user = null): FormulaVersion
    {
        return DB::transaction(function () use ($formula, $base, $lines, $reason, $balanceEvents, $user): FormulaVersion {
            $events = $this->describeBalanceEvents($balanceEvents);
            $note = $events === [] ? null : implode('; ', array_map(
                fn (array $event): string => __('formulas.balance_note', ['material' => $event['material'], 'before' => $event['before'] ?? '—', 'after' => $event['after']]),
                $events,
            ));

            $version = $this->formulaVersionService->createVersion(
                $formula,
                $lines,
                $note === null ? $reason : "{$reason} [{$note}]",
                $user,
                $base,
            );

            ($this->recordFormulaBalance)($version, $events, $note);

            return $version;
        });
    }

    /**
     * @param  list<array{material_id: string, before?: string|null, after: string}>  $events
     * @return list<array{material: string, before: string|null, after: string}>
     */
    private function describeBalanceEvents(array $events): array
    {
        $names = TradeMaterial::query()->whereIn('id', array_column($events, 'material_id'))->pluck('trade_name', 'id');

        return array_map(fn (array $event): array => [
            'material' => (string) ($names[$event['material_id']] ?? $event['material_id']),
            'before' => $event['before'] ?? null,
            'after' => $event['after'],
        ], $events);
    }

    /** @return array<string, mixed> */
    private function shared(): array
    {
        return [
            'datasetVersion' => DatasetVersion::query()->latest('created_at')->value('id') ?? config('formula.unpublished_dataset_version'),
            'tierOptions' => ProductTier::options(),
        ];
    }

    /** A stored decimal without trailing zeros ("64.500000" -> "64.5") so the editor shows what a person typed. */
    private function plain(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
