import { dec, isDecimalString } from '../core/decimal';
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

/** A formula line where exactly one is declared `qs` (quantum satis): it absorbs 100 − Σ(others). */
export interface QsLine {
  materialId: string;
  /** Ignored for the qs line (kept only to record `before`). */
  percentWW?: string;
  qs?: boolean;
}

/** History record of the balancing (what the qs line was and what it became). */
export interface BalanceEvent {
  materialId: string;
  /** Previous percent of the qs line, or null if it had none. */
  before: string | null;
  after: string;
}

export interface BalancedFormula {
  lines: FormulaLine[];
  balanceEvent: BalanceEvent;
}

function toLine(l: QsLine): FormulaLine {
  return { materialId: l.materialId, percentWW: l.percentWW ?? '' };
}

/**
 * Balances the single declared qs line to 100 − Σ(others). Other lines are never touched, so
 * active matter of every other material is unchanged. Errors: no qs, more than one qs,
 * invalid other lines, or balance ≤ 0.
 */
export function balanceQs(lines: readonly QsLine[]): FormulaResult<BalancedFormula> {
  const qsLines = lines.filter((l) => l.qs === true);
  if (qsLines.length === 0) {
    return formulaFail([issue('QS_MISSING')]);
  }
  if (qsLines.length > 1) {
    return formulaFail(qsLines.map((l) => issue('QS_MULTIPLE', l.materialId)));
  }
  const target = qsLines[0] as QsLine;
  const others = lines.filter((l) => l !== target);
  const errors: FormulaIssue[] = [
    ...percentIssues(others.map(toLine)),
    ...duplicateIssues(lines.map((l) => l.materialId)),
  ];
  if (target.percentWW !== undefined && !isDecimalString(target.percentWW)) {
    errors.push(issue('INVALID_PERCENT', target.materialId, { percentWW: target.percentWW }));
  }
  if (errors.length > 0) {
    return formulaFail(errors);
  }
  const balance = others.reduce((acc, l) => acc.minus(l.percentWW as string), dec('100'));
  if (balance.lte(0)) {
    return formulaFail([issue('QS_NON_POSITIVE_BALANCE', target.materialId, { balance: balance.toFixed() })]);
  }
  const after = balance.toFixed();
  return formulaOk({
    lines: lines.map((l) => ({ materialId: l.materialId, percentWW: l === target ? after : (l.percentWW as string) })),
    balanceEvent: { materialId: target.materialId, before: target.percentWW ?? null, after },
  });
}
