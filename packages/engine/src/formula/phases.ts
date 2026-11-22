import { dec, isDecimalString, type Dec } from '../core/decimal';
import { SUM_TOLERANCE } from './validate';
import { formulaFail, formulaOk, issue, type FormulaIssue, type FormulaResult } from './issues';

/**
 * The process phases of an emulsion formula (M-01): the water phase, the oil phase and the cool-down phase (what is
 * added after the batch has cooled). The codes are the ones the app stores on a formula line.
 */
export const FORMULA_PHASES = ['water', 'oil', 'cool_down'] as const;
export type FormulaPhase = (typeof FORMULA_PHASES)[number];

export interface PhasedLine {
  materialId: string;
  /** Percent w/w as a decimal string (a q.s. line is balanced first, see balanceQs). */
  percentWW: string;
  /** One of FORMULA_PHASES; null, undefined or '' means the line has no phase. */
  phase?: string | null;
  /** Temperature in °C the line is added at; a plain decimal string, optional. */
  additionTempC?: string | null;
}

export interface PhaseTotal {
  phase: FormulaPhase;
  /** Σ % w/w of the lines in the phase, an exact decimal string. */
  sum: string;
  lines: number;
}

export interface PhaseSummary {
  /** The phases that have at least one line, in FORMULA_PHASES order. */
  totals: PhaseTotal[];
  /** Σ over the lines that carry no phase. */
  unphased: { sum: string; lines: number };
  /** Σ of every parseable line (phased or not). */
  sum: string;
  /** sum − 100, signed. */
  deviation: string;
  withinTolerance: boolean;
}

const blank = (value: string | null | undefined): boolean => value === undefined || value === null || value === '';
const isPhase = (value: string): value is FormulaPhase => (FORMULA_PHASES as readonly string[]).includes(value);

/** A temperature is a plain, non-negative decimal. No upper limit is assumed here: what is sensible per ingredient is the lab's call. */
export function isAdditionTemp(value: string): boolean {
  return isDecimalString(value) && dec(value).gte(0);
}

/** The sum of each phase, of the lines without a phase, and of everything. Never fails: a draft may be incomplete. */
export function phaseSummary(lines: readonly PhasedLine[]): PhaseSummary {
  const perPhase = new Map<FormulaPhase, { sum: Dec; lines: number }>();
  let unphased = { sum: dec('0'), lines: 0 };
  let total: Dec = dec('0');
  for (const line of lines) {
    const percent = isDecimalString(line.percentWW) ? dec(line.percentWW) : dec('0');
    total = total.plus(percent);
    if (!blank(line.phase) && isPhase(line.phase as string)) {
      const entry = perPhase.get(line.phase as FormulaPhase) ?? { sum: dec('0'), lines: 0 };
      perPhase.set(line.phase as FormulaPhase, { sum: entry.sum.plus(percent), lines: entry.lines + 1 });
    } else {
      unphased = { sum: unphased.sum.plus(percent), lines: unphased.lines + 1 };
    }
  }
  const deviation = total.minus(100);
  return {
    totals: FORMULA_PHASES.flatMap((phase) => {
      const entry = perPhase.get(phase);
      return entry === undefined ? [] : [{ phase, sum: entry.sum.toFixed(), lines: entry.lines }];
    }),
    unphased: { sum: unphased.sum.toFixed(), lines: unphased.lines },
    sum: total.toFixed(),
    deviation: deviation.toFixed(),
    withinTolerance: deviation.abs().lte(SUM_TOLERANCE),
  };
}

/**
 * Validates the phases of a formula. Phases are optional: a formula with no phase on any line is valid (the existing
 * editor behaviour). Once one line has a phase, every line must have one, each must be a known phase, and the phase
 * sums must add up to 100 (±0.001). An addition temperature needs a phase and must be a non-negative decimal.
 * Every problem is reported at once.
 */
export function validatePhases(lines: readonly PhasedLine[]): FormulaResult<PhaseSummary> {
  const summary = phaseSummary(lines);
  const errors: FormulaIssue[] = [];
  const anyPhase = lines.some((line) => !blank(line.phase));
  for (const line of lines) {
    if (!blank(line.phase) && !isPhase(line.phase as string)) {
      errors.push(issue('PHASE_UNKNOWN', line.materialId, { phase: String(line.phase) }));
    } else if (blank(line.phase) && anyPhase) {
      errors.push(issue('PHASE_MISSING', line.materialId));
    }
    if (!blank(line.additionTempC)) {
      if (!isAdditionTemp(line.additionTempC as string)) {
        errors.push(issue('PHASE_TEMP_INVALID', line.materialId, { additionTempC: String(line.additionTempC) }));
      } else if (blank(line.phase)) {
        errors.push(issue('PHASE_TEMP_WITHOUT_PHASE', line.materialId));
      }
    }
  }
  const allParseable = lines.every((line) => isDecimalString(line.percentWW));
  if (anyPhase && errors.length === 0 && allParseable && !summary.withinTolerance) {
    errors.push(issue('PHASE_SUM_NOT_100', undefined, { sum: summary.sum, deviation: summary.deviation }));
  }
  return errors.length > 0 ? formulaFail(errors) : formulaOk(summary);
}
