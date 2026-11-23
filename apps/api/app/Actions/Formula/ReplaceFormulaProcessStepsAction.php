<?php

namespace App\Actions\Formula;

use App\Enums\FormulaStatus;
use App\Exceptions\Domain\FormulaProcessException;
use App\Models\FormulaProcessCheck;
use App\Models\FormulaProcessStep;
use App\Models\FormulaVersion;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the manufacturing steps of a DRAFT formula version and re-hashes the version (the steps are part of its content).
 * An approved or rejected version never changes: the database refuses it too (trigger), this refuses it first with a message.
 */
final class ReplaceFormulaProcessStepsAction
{
    /**
     * @param  list<array{kind: string, phase?: string|null, line_no?: string|int|null, temperature_c?: string|null, mixing_speed_rpm?: string|null, duration_minutes?: string|null, note?: string|null, checks?: list<array{property: string, unit: string, limit_min?: string|null, limit_max?: string|null, source_id?: string|null, confidence?: string|null}>}>  $steps
     *
     * @throws FormulaProcessException When the version is not a draft, an `add` step names no (or an unknown) line, a unit does not fit its property or a limit has no source.
     */
    public function __invoke(FormulaVersion $version, array $steps): FormulaVersion
    {
        return DB::transaction(function () use ($version, $steps): FormulaVersion {
            $locked = FormulaVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== FormulaStatus::Draft) {
                throw FormulaProcessException::versionNotDraft($locked->version);
            }

            $lines = $locked->lines()->get()->keyBy(fn ($line): string => (string) $line->line_no);

            foreach ($locked->processSteps()->get() as $existing) {
                $existing->checks()->get()->each->delete();
                $existing->delete();
            }

            foreach (array_values($steps) as $index => $data) {
                $number = $index + 1;
                $lineId = null;
                if (($data['line_no'] ?? null) !== null && $data['line_no'] !== '') {
                    $line = $lines->get((string) $data['line_no']);
                    if ($line === null) {
                        throw FormulaProcessException::unknownLine($number, (string) $data['line_no']);
                    }
                    $lineId = $line->getKey();
                }
                if ($data['kind'] === 'add' && $lineId === null) {
                    throw FormulaProcessException::addNeedsLine($number);
                }

                $step = FormulaProcessStep::query()->create([
                    'formula_version_id' => $locked->getKey(),
                    'step_no' => $number,
                    'kind' => $data['kind'],
                    'phase' => ($data['phase'] ?? '') === '' ? null : $data['phase'],
                    'formula_line_id' => $lineId,
                    'temperature_c' => $this->decimal($data['temperature_c'] ?? null),
                    'mixing_speed_rpm' => $this->decimal($data['mixing_speed_rpm'] ?? null),
                    'duration_minutes' => $this->decimal($data['duration_minutes'] ?? null),
                    'note' => ($data['note'] ?? '') === '' ? null : $data['note'],
                ]);

                foreach (array_values($data['checks'] ?? []) as $checkIndex => $check) {
                    $checkNumber = $checkIndex + 1;
                    if (! in_array($check['unit'], (array) config("production.check_units.{$check['property']}", []), true)) {
                        throw FormulaProcessException::unitNotAllowed($number, $check['unit'], $check['property']);
                    }
                    $min = $this->decimal($check['limit_min'] ?? null);
                    $max = $this->decimal($check['limit_max'] ?? null);
                    $sourceId = ($check['source_id'] ?? '') === '' ? null : $check['source_id'];
                    $confidence = ($check['confidence'] ?? '') === '' ? null : $check['confidence'];
                    if (($min !== null || $max !== null) && ($sourceId === null || $confidence === null)) {
                        throw FormulaProcessException::limitNeedsSource($number, $checkNumber);
                    }
                    FormulaProcessCheck::query()->create([
                        'formula_process_step_id' => $step->getKey(),
                        'check_no' => $checkNumber,
                        'property' => $check['property'],
                        'unit' => $check['unit'],
                        'limit_min' => $min,
                        'limit_max' => $max,
                        'source_id' => $min === null && $max === null ? null : $sourceId,
                        'confidence' => $min === null && $max === null ? null : $confidence,
                    ]);
                }
            }

            $locked->forceFill(['hash' => CanonicalJson::hash($locked->canonicalContent())])->save();

            return $locked->load('processSteps.checks');
        });
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
