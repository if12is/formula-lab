import { dec, isDecimalString, type Dec } from '../core/decimal';
import {
  duplicateIssues,
  formulaFail,
  formulaOk,
  issue,
  percentIssues,
  type FormulaIssue,
  type FormulaLine,
  type FormulaResult,
} from './issues';

/** Σ must equal 100 within ±0.001 (plan/00-research/04-deterministic-formulas.md). */
export const SUM_TOLERANCE = '0.001';

/** Sum of the parseable percentages (unparseable lines are reported elsewhere). */
function sumOf(lines: readonly FormulaLine[]): Dec {
  return lines.reduce(
    (acc, l) => (isDecimalString(l.percentWW) ? acc.plus(l.percentWW) : acc),
    dec('0'),
  );
}

export interface FormulaSummary {
  sum: string;
  /** sum − 100, signed. */
  deviation: string;
  withinTolerance: boolean;
}

function summarize(lines: readonly FormulaLine[]): FormulaSummary {
  const sum = sumOf(lines);
  const deviation = sum.minus(100);
  return {
    sum: sum.toFixed(),
    deviation: deviation.toFixed(),
    withinTolerance: deviation.abs().lte(SUM_TOLERANCE),
  };
}

/**
 * Strict validation for calculable versions. Reports every problem at once:
 * empty formula, duplicate materials, non-positive/unparseable percents, and Σ ≠ 100 (±0.001).
 */
export function validateFormula(lines: readonly FormulaLine[]): FormulaResult<FormulaSummary> {
  if (lines.length === 0) {
    return formulaFail([issue('EMPTY')]);
  }
  const errors: FormulaIssue[] = [
    ...percentIssues(lines),
    ...duplicateIssues(lines.map((l) => l.materialId)),
  ];
  const summary = summarize(lines);
  const allParseable = lines.every((l) => isDecimalString(l.percentWW));
  if (allParseable && !summary.withinTolerance) {
    errors.push(issue('SUM_NOT_100', undefined, { sum: summary.sum, deviation: summary.deviation }));
  }
  return errors.length > 0 ? formulaFail(errors) : formulaOk(summary);
}

export interface DraftSummary extends FormulaSummary {
  /** Line-level problems only; a draft may legitimately not sum to 100. */
  issues: FormulaIssue[];
}

/** Drafts may be saved in any state: this only reports the sum, its deviation and line problems. */
export function draftSummary(lines: readonly FormulaLine[]): DraftSummary {
  return {
    ...summarize(lines),
    issues: [...percentIssues(lines), ...duplicateIssues(lines.map((l) => l.materialId))],
  };
}
