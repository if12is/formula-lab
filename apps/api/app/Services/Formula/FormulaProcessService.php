<?php

namespace App\Services\Formula;

use App\Actions\Formula\ReplaceFormulaProcessStepsAction;
use App\Enums\Confidence;
use App\Enums\FormulaPhase;
use App\Enums\FormulaStatus;
use App\Enums\Production\ProcessStepKind;
use App\Enums\SourceKind;
use App\Exceptions\Domain\FormulaProcessException;
use App\Models\Formula;
use App\Models\FormulaProcessCheck;
use App\Models\FormulaProcessStep;
use App\Models\FormulaVersion;
use App\Support\DecimalNormalizer;

/** The single write path of a version's manufacturing steps (Q-10), and how the screen reads them. */
final class FormulaProcessService
{
    public function __construct(private readonly ReplaceFormulaProcessStepsAction $replaceSteps) {}

    /**
     * @param  list<array<string, mixed>>  $steps
     *
     * @throws FormulaProcessException
     */
    public function replace(FormulaVersion $version, array $steps): FormulaVersion
    {
        return ($this->replaceSteps)($version, $steps);
    }

    /**
     * The steps of a version as the screen reads and edits them (line numbers, not ids), with the choices of the form.
     *
     * @return array<string, mixed>
     */
    public function panel(Formula $formula, FormulaVersion $version): array
    {
        $version->load(['lines.tradeMaterial', 'processSteps.checks.source', 'processSteps.formulaLine']);

        return [
            'formula' => ['id' => $formula->getKey(), 'code' => $formula->code, 'name' => $formula->name],
            'version' => ['number' => $version->version, 'status' => $version->status->value, 'hash' => $version->hash, 'editable' => $version->status === FormulaStatus::Draft],
            'lines' => $version->lines->map(fn ($line): array => [
                'value' => (string) $line->line_no,
                'label' => $line->line_no.' — '.$line->tradeMaterial->trade_name,
                'phase' => $line->phase,
            ])->all(),
            'steps' => $version->processSteps->map(fn (FormulaProcessStep $step): array => [
                'step_no' => (string) $step->step_no,
                'kind' => $step->kind->value,
                'phase' => $step->phase,
                'line_no' => $step->formulaLine === null ? null : (string) $step->formulaLine->line_no,
                'temperature_c' => $this->plain($step->temperature_c),
                'mixing_speed_rpm' => $this->plain($step->mixing_speed_rpm),
                'duration_minutes' => $this->plain($step->duration_minutes),
                'note' => $step->note,
                'checks' => $step->checks->map(fn (FormulaProcessCheck $check): array => [
                    'check_no' => (string) $check->check_no,
                    'property' => $check->property,
                    'unit' => $check->unit,
                    'limit_min' => $this->plain($check->limit_min),
                    'limit_max' => $this->plain($check->limit_max),
                    'source_id' => $check->source_id,
                    'source_label' => $check->source?->title,
                    'confidence' => $check->confidence?->value,
                ])->all(),
            ])->all(),
            'choices' => [
                'kinds' => ProcessStepKind::options(),
                'phases' => FormulaPhase::options(),
                'properties' => array_map(fn (string $property): array => ['value' => $property, 'label' => __("production.check_properties.{$property}")], config('production.check_properties')),
                'units' => config('production.check_units'),
                'confidences' => Confidence::options(),
                'sourceKinds' => SourceKind::selectableOptions(),
            ],
            'urls' => [
                'save' => route('formulas.versions.process.update', [$formula->getKey(), $version->version], false),
                'editor' => route('formulas.versions.edit', [$formula->getKey(), $version->version], false),
                'sources' => route('sources.search', [], false),
                'sourceStore' => route('sources.store', [], false),
                'newOrder' => route('production.orders.create', ['formula_version' => $version->getKey()], false),
            ],
        ];
    }

    private function plain(?string $value): ?string
    {
        return $value === null ? null : DecimalNormalizer::normalize($value);
    }
}
