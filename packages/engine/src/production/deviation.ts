/**
 * Planned-versus-actual comparison for a production batch (Q-11, Q-04, Q-09). Pure, decimal.js only.
 *
 *   deviation    = actual - planned                       (signed, same unit as the inputs)
 *   deviationPct = (actual - planned) / planned x 100     (signed)
 *
 * The threshold above which a deviation needs approval is an INPUT (`thresholdPct`): the engine ships no number. A null
 * threshold never reads as "within": the verdict is `threshold_not_set` and the caller decides how to treat it
 * (the app asks for approval of any non-zero deviation, the safe side).
 */
import { dec, isDecimalString, type Dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { roundPercent } from '../core/round';
import { inputLeaf, traced, type Traced } from '../trace/trace';
import { yieldFraction } from '../batch/wastage';

export const PRODUCTION_REASONS = {
  PLANNED_INVALID: 'production.planned_invalid',
  ACTUAL_INVALID: 'production.actual_invalid',
  PLANNED_NOT_POSITIVE: 'production.planned_not_positive',
  THRESHOLD_INVALID: 'production.threshold_invalid',
  CHARGED_INVALID: 'production.charged_mass_invalid',
} as const;

export type DeviationVerdict = 'none' | 'within' | 'exceeds' | 'threshold_not_set';

export interface DeviationInput {
  planned: string;
  actual: string;
  unit: Parameters<typeof inputLeaf>[2];
  /** Percent, a decimal string; null/undefined = the owner has not set one. */
  thresholdPct?: string | null;
  /** Names the figure in a missing[] entry, e.g. `line.3.weight`. */
  property?: string;
}

export interface Deviation {
  planned: string;
  actual: string;
  /** actual - planned. */
  difference: Traced;
  /** (actual - planned) / planned x 100, exact. */
  differencePct: Traced;
  /** differencePct at the display step (0.001 %). */
  differencePctDisplay: string;
  thresholdPct: string | null;
  verdict: DeviationVerdict;
  /** true when the deviation is above the threshold; null when no threshold is set (not evaluated). */
  exceedsThreshold: boolean | null;
}

function parse(value: string): Dec | null {
  return isDecimalString(value) ? dec(value) : null;
}

export function evaluateDeviation(input: DeviationInput): Result<Deviation> {
  const property = input.property ?? 'value';
  const planned = parse(input.planned);
  const actual = parse(input.actual);
  if (planned === null) {
    return missing([{ property, reason: PRODUCTION_REASONS.PLANNED_INVALID }]);
  }
  if (actual === null || actual.isNegative()) {
    return missing([{ property, reason: PRODUCTION_REASONS.ACTUAL_INVALID }]);
  }
  if (planned.lte(0)) {
    return missing([{ property, reason: PRODUCTION_REASONS.PLANNED_NOT_POSITIVE }]);
  }
  const threshold = input.thresholdPct === undefined || input.thresholdPct === null ? null : parse(input.thresholdPct);
  if (input.thresholdPct !== undefined && input.thresholdPct !== null && (threshold === null || threshold.isNegative())) {
    return missing([{ property: 'thresholdPct', reason: PRODUCTION_REASONS.THRESHOLD_INVALID }]);
  }

  const plannedLeaf = inputLeaf('Planned', planned, input.unit);
  const actualLeaf = inputLeaf('Actual', actual, input.unit);
  const difference = traced('sub', 'Difference = actual - planned', actual.minus(planned), input.unit, [actualLeaf, plannedLeaf]);
  const pct = actual.minus(planned).div(planned).mul(100);
  const differencePct = traced('div', 'Deviation % = (actual - planned) / planned x 100', pct, '%', [difference, plannedLeaf]);

  let verdict: DeviationVerdict;
  let exceeds: boolean | null;
  if (pct.isZero()) {
    verdict = 'none';
    exceeds = false;
  } else if (threshold === null) {
    verdict = 'threshold_not_set';
    exceeds = null;
  } else {
    exceeds = pct.abs().gt(threshold);
    verdict = exceeds ? 'exceeds' : 'within';
  }

  return ok({
    planned: planned.toFixed(),
    actual: actual.toFixed(),
    difference,
    differencePct,
    differencePctDisplay: roundPercent(pct),
    thresholdPct: threshold === null ? null : threshold.toFixed(),
    verdict,
    exceedsThreshold: exceeds,
  });
}

export interface WasteInput {
  /** Mass charged into the batch, g (the sum of the weighed lines). */
  chargedMassG: string;
  /** Planned waste, % of the charged mass (same basis as planBatch's wastage). */
  plannedWastePct: string;
  actualWasteG: string;
  thresholdPct?: string | null;
}

export interface WasteComparison {
  plannedWasteG: Traced;
  deviation: Deviation;
}

/** Planned waste = charged x w/100 against the waste actually recorded (Q-04, reporting side: the stock effect is Sanad's). */
export function compareWaste(input: WasteInput): Result<WasteComparison> {
  const charged = parse(input.chargedMassG);
  if (charged === null || charged.lte(0)) {
    return missing([{ property: 'chargedMassG', reason: PRODUCTION_REASONS.CHARGED_INVALID }]);
  }
  const yieldF = yieldFraction(input.plannedWastePct);
  if (!yieldF.ok) {
    return yieldF;
  }
  const w = dec(input.plannedWastePct);
  const chargedLeaf = inputLeaf('Charged mass', charged, 'g');
  const pctLeaf = inputLeaf('Planned waste', w, '%w/w');
  const plannedWaste = traced('mul', 'Planned waste = charged x w / 100', charged.mul(w).div(100), 'g', [chargedLeaf, pctLeaf]);
  if (plannedWaste.value === '0') {
    // A planned waste of exactly zero cannot be the base of a percentage: report the plain difference only as missing the base.
    return missing([{ property: 'plannedWastePct', reason: PRODUCTION_REASONS.PLANNED_NOT_POSITIVE }]);
  }
  const deviation = evaluateDeviation({
    planned: plannedWaste.value,
    actual: input.actualWasteG,
    unit: 'g',
    thresholdPct: input.thresholdPct,
    property: 'actualWasteG',
  });
  if (!deviation.ok) {
    return deviation;
  }
  return ok({ plannedWasteG: plannedWaste, deviation: deviation.value }, deviation.warnings);
}

export type LimitStatus = 'PASS' | 'FAIL' | 'UNKNOWN';

export interface LimitCheck {
  status: LimitStatus;
  /** Why the status is UNKNOWN, when it is. */
  reason?: string;
}

/**
 * A reading against the limits of a check point. A limit that is null is not defined: with neither limit the reading is UNKNOWN
 * ("not evaluated"), never PASS. A value on a limit passes.
 */
export function checkLimits(input: { value: string; min?: string | null; max?: string | null }): Result<LimitCheck> {
  const value = parse(input.value);
  if (value === null) {
    return missing([{ property: 'value', reason: PRODUCTION_REASONS.ACTUAL_INVALID }]);
  }
  const min = input.min === undefined || input.min === null ? null : parse(input.min);
  const max = input.max === undefined || input.max === null ? null : parse(input.max);
  if ((input.min !== undefined && input.min !== null && min === null) || (input.max !== undefined && input.max !== null && max === null)) {
    return missing([{ property: 'limits', reason: PRODUCTION_REASONS.THRESHOLD_INVALID }]);
  }
  if (min === null && max === null) {
    return ok({ status: 'UNKNOWN', reason: 'production.limits_not_defined' });
  }
  const below = min !== null && value.lt(min);
  const above = max !== null && value.gt(max);
  return ok({ status: below || above ? 'FAIL' : 'PASS' });
}
