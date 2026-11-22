import { dec, isDecimalString } from '../core/decimal';

export interface FormulaLine {
  materialId: string;
  /** Percent w/w as a decimal string. */
  percentWW: string;
}

/** Stable machine codes; the UI maps `messageKey` to Arabic text. */
export const FORMULA_MESSAGE_KEYS = {
  EMPTY: 'formula.empty',
  SUM_NOT_100: 'formula.sum_not_100',
  NON_POSITIVE_PERCENT: 'formula.percent_not_positive',
  INVALID_PERCENT: 'formula.percent_invalid',
  DUPLICATE_MATERIAL: 'formula.duplicate_material',
  QS_MISSING: 'formula.qs_missing',
  QS_MULTIPLE: 'formula.qs_multiple',
  QS_NON_POSITIVE_BALANCE: 'formula.qs_balance_not_positive',
  PHASE_UNKNOWN: 'formula.phase_unknown',
  PHASE_MISSING: 'formula.phase_missing',
  PHASE_TEMP_INVALID: 'formula.phase_temp_invalid',
  PHASE_TEMP_WITHOUT_PHASE: 'formula.phase_temp_without_phase',
  PHASE_SUM_NOT_100: 'formula.phase_sum_not_100',
} as const;

export type FormulaIssueCode = keyof typeof FORMULA_MESSAGE_KEYS;

export interface FormulaIssue {
  code: FormulaIssueCode;
  messageKey: string;
  materialId?: string;
  /** Decimal-string details such as sum and deviation, for message interpolation. */
  detail?: Record<string, string>;
}

export type FormulaResult<T> =
  | { ok: true; value: T; warnings: string[] }
  | { ok: false; errors: FormulaIssue[]; warnings: string[] };

export function issue(code: FormulaIssueCode, materialId?: string, detail?: Record<string, string>): FormulaIssue {
  const out: FormulaIssue = { code, messageKey: FORMULA_MESSAGE_KEYS[code] };
  if (materialId !== undefined) {
    out.materialId = materialId;
  }
  if (detail !== undefined) {
    out.detail = detail;
  }
  return out;
}

export function formulaOk<T>(value: T, warnings: string[] = []): FormulaResult<T> {
  return { ok: true, value, warnings };
}

export function formulaFail<T = never>(errors: FormulaIssue[], warnings: string[] = []): FormulaResult<T> {
  return { ok: false, errors, warnings };
}

/** Per-line problems: unparseable percent, or percent <= 0. */
export function percentIssues(lines: readonly FormulaLine[]): FormulaIssue[] {
  const out: FormulaIssue[] = [];
  for (const line of lines) {
    if (!isDecimalString(line.percentWW)) {
      out.push(issue('INVALID_PERCENT', line.materialId, { percentWW: String(line.percentWW) }));
    } else if (dec(line.percentWW).lte(0)) {
      out.push(issue('NON_POSITIVE_PERCENT', line.materialId, { percentWW: line.percentWW }));
    }
  }
  return out;
}

/** One issue per material id that appears more than once. */
export function duplicateIssues(materialIds: readonly string[]): FormulaIssue[] {
  const seen = new Set<string>();
  const reported = new Set<string>();
  const out: FormulaIssue[] = [];
  for (const id of materialIds) {
    if (seen.has(id) && !reported.has(id)) {
      reported.add(id);
      out.push(issue('DUPLICATE_MATERIAL', id));
    }
    seen.add(id);
  }
  return out;
}
