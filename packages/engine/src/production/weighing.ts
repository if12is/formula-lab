/**
 * The weighing sheet of a production order (Q-02). Pure, decimal.js only.
 *
 * The lines are balanced (the q.s. line takes 100 - sum of the others), scaled to the batch mass, and each weight is
 * rounded ONCE to the registered balance resolution (half-even, core/round.ts roundMass). The sheet reports the rounded
 * total next to the exact one and the residual between them, never redistributing it. Addition order: the order the
 * process steps give a line (`additionOrder`), then the phase order (water, oil, cool-down), then the line number.
 * A line whose rounded weight is zero cannot be weighed at this resolution: it is reported, not hidden.
 */
import { dec, isDecimalString } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { Quantity } from '../core/quantity';
import { roundingResidual, roundToStep, type RoundingResidual } from '../core/round';
import { toContractDecimal } from './contract-decimal';
import { FORMULA_PHASES, type PhasedLine } from '../formula/phases';
import { balanceQs } from '../formula/qs';
import { scaleFormula } from '../formula/scale';
import { canonicalJson, sha256Hex } from '../hash';

export const WEIGHING_REASONS = {
  RESOLUTION_MISSING: 'production.scale_resolution_missing',
  RESOLUTION_INVALID: 'production.scale_resolution_invalid',
  BATCH_INVALID: 'production.batch_size_invalid',
  BATCH_UNIT: 'production.batch_unit_not_mass',
} as const;

export const WEIGHING_WARNINGS = {
  BELOW_RESOLUTION: 'production.weight_below_resolution',
  ROUNDING_RESIDUAL: 'production.rounding_residual',
} as const;

export interface WeighingLineInput extends Omit<PhasedLine, 'percentWW'> {
  lineNo: string;
  percentWW?: string | null;
  qs?: boolean;
  /** Position given by the formula's process steps; lower first. */
  additionOrder?: number | null;
}

export interface WeighingLine {
  /** 1-based position on the sheet. */
  order: number;
  lineNo: string;
  materialId: string;
  phase: string | null;
  additionTempC: string | null;
  percentWW: string;
  /** Exact mass in g. */
  exactG: string;
  /** What the operator weighs: the exact mass rounded to the resolution, in g. */
  weighG: string;
  /** The exact mass in kg at the 6 decimals of the Sanad contract (what the order asks Sanad to consume). */
  consumeKg: string;
}

export interface WeighingSheet {
  batchMassG: string;
  resolutionG: string;
  lines: WeighingLine[];
  /** Σ exact masses, g. */
  totalExactG: string;
  /** Σ rounded weights, g. */
  totalWeighG: string;
  rounding: RoundingResidual;
  belowResolution: string[];
  /** SHA-256 of the canonical content of the sheet: the same inputs always give the same hash. */
  sheetHash: string;
}

const phaseRank = (phase: string | null | undefined): number => {
  const index = (FORMULA_PHASES as readonly string[]).indexOf(phase ?? '');
  return index === -1 ? FORMULA_PHASES.length : index;
};

export function weighingSheet(input: {
  lines: readonly WeighingLineInput[];
  batchMass: { value: string; unit: string };
  resolutionG: string | null | undefined;
}): Result<WeighingSheet> {
  if (input.resolutionG === undefined || input.resolutionG === null || input.resolutionG === '') {
    return missing([{ property: 'scaleResolutionG', reason: WEIGHING_REASONS.RESOLUTION_MISSING }]);
  }
  if (!isDecimalString(input.resolutionG) || dec(input.resolutionG).lte(0)) {
    return missing([{ property: 'scaleResolutionG', reason: WEIGHING_REASONS.RESOLUTION_INVALID }]);
  }
  if (input.batchMass.unit !== 'kg' && input.batchMass.unit !== 'g') {
    return missing([{ property: 'batchSize', reason: WEIGHING_REASONS.BATCH_UNIT }]);
  }
  if (!isDecimalString(input.batchMass.value) || dec(input.batchMass.value).lte(0)) {
    return missing([{ property: 'batchSize', reason: WEIGHING_REASONS.BATCH_INVALID }]);
  }
  const batch = new Quantity(input.batchMass.value, input.batchMass.unit);

  const balanced = balanceQs(input.lines.map((l) => ({ materialId: l.materialId, ...(l.percentWW ? { percentWW: l.percentWW } : {}), ...(l.qs ? { qs: true } : {}) })));
  // A formula with no q.s. line is valid as long as it adds up to 100: balanceQs only applies when one is declared.
  const hasQs = input.lines.some((l) => l.qs === true);
  const percents = hasQs
    ? balanced.ok
      ? balanced.value.lines
      : null
    : input.lines.map((l) => ({ materialId: l.materialId, percentWW: l.percentWW ?? '' }));
  if (percents === null) {
    return missing(balanced.ok ? [] : balanced.errors.map((e) => ({ materialId: e.materialId, property: 'formula', reason: e.messageKey })));
  }
  const scaled = scaleFormula(percents, batch);
  if (!scaled.ok) {
    return missing(scaled.errors.map((e) => ({ materialId: e.materialId, property: 'formula', reason: e.messageKey })));
  }

  const sorted = input.lines
    .map((line, index) => ({ line, mass: (scaled.value.lines[index] as (typeof scaled.value.lines)[number]), percent: (percents[index] as (typeof percents)[number]).percentWW }))
    .sort((a, b) =>
      (a.line.additionOrder ?? Number.MAX_SAFE_INTEGER) - (b.line.additionOrder ?? Number.MAX_SAFE_INTEGER)
      || phaseRank(a.line.phase) - phaseRank(b.line.phase)
      || dec(a.line.lineNo).cmp(dec(b.line.lineNo)));

  const step = input.resolutionG;
  const lines: WeighingLine[] = sorted.map(({ line, mass, percent }, index) => {
    const exact = new Quantity(mass.mass.value, mass.mass.unit as 'kg' | 'g').to('g').value;
    return {
      order: index + 1,
      lineNo: line.lineNo,
      materialId: line.materialId,
      phase: line.phase && line.phase !== '' ? line.phase : null,
      additionTempC: line.additionTempC ?? null,
      percentWW: percent,
      exactG: exact.toFixed(),
      weighG: roundToStep(exact, step),
      consumeKg: toContractDecimal(exact.div(1000)),
    };
  });

  const totalExact = lines.reduce((acc, l) => acc.plus(l.exactG), dec('0'));
  const totalWeigh = lines.reduce((acc, l) => acc.plus(l.weighG), dec('0'));
  const rounding = roundingResidual(lines.map((l) => l.exactG), batch.to('g').value, step);
  const belowResolution = lines.filter((l) => dec(l.weighG).isZero() && dec(l.exactG).gt(0)).map((l) => l.lineNo);
  const warnings = [
    ...(belowResolution.length > 0 ? [`${WEIGHING_WARNINGS.BELOW_RESOLUTION}:${belowResolution.join(',')}`] : []),
    ...(rounding.hasResidual ? [WEIGHING_WARNINGS.ROUNDING_RESIDUAL] : []),
  ];

  const content = {
    batchMassG: batch.to('g').value.toFixed(),
    resolutionG: step,
    lines: lines.map((l) => ({ ...l, order: String(l.order) })),
  };
  return ok(
    {
      batchMassG: content.batchMassG,
      resolutionG: step,
      lines,
      totalExactG: totalExact.toFixed(),
      totalWeighG: totalWeigh.toFixed(),
      rounding,
      belowResolution,
      sheetHash: sha256Hex(canonicalJson(content)),
    },
    warnings,
  );
}
