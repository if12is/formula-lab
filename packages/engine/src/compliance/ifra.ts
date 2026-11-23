/**
 * IFRA check (N-04, and L-09 which is the same check per product category).
 * Each fragrance oil is checked against ITS OWN IFRA certificate: oil % in the product <= the certificate's
 * limit for the product's IFRA category. Limits are sourced inputs (nothing built in). A missing category,
 * certificate or limit gives UNKNOWN, never PASS. The comparison itself is `checkIfraLimit`.
 */
import type { MissingData } from '../contract';
import { dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import { resolveValue } from '../data/resolve';
import { checkIfraLimit, type IfraCheck } from '../fragrance/fine-fragrance';
import type { Confidence } from '../contract';
import type { Unit } from '../core/quantity';
import type { ConfidencePolicy } from '../trace/sourced';
import { inputLeaf, traced } from '../trace/trace';
import type { CheckStatus } from '../core/check-status';
import { validFormulaLines, type ComplianceLine } from './composition';
import { overallStatus, type RuleStatus } from './evaluate';

export const IFRA_REASONS = {
  CATEGORY_MISSING: 'ifra.category_missing',
  CERTIFICATE_MISSING: 'ifra.certificate_missing',
  LIMIT_MISSING_FOR_CATEGORY: 'ifra.limit_missing_for_category',
  FORMULA_LINE_INVALID: 'ifra.formula_line_invalid',
} as const;

export const IFRA_MESSAGE_KEY = 'compliance.ifra.fragrance_limit';

export interface IfraCertificate {
  /** Category (e.g. "4", "5A") -> max % w/w of the finished product, each a sourced value. */
  limitsByCategory: Record<string, SourcedScalar>;
}

export interface IfraFragrance {
  materialId: string;
  /** Absent = no certificate on file. */
  certificate?: IfraCertificate;
}

export interface IfraInput {
  /** IFRA category of the product, from its product family (see `ifraCategoryForFamily`). */
  ifraCategory?: string;
  lines: ComplianceLine[];
  fragrances: IfraFragrance[];
  policy?: ConfidencePolicy;
}

export interface IfraResult {
  materialId: string;
  category: string | null;
  status: RuleStatus;
  check?: IfraCheck;
  messageKey: string;
  params: Record<string, string>;
  missing: MissingData[];
}

export interface IfraReport {
  overall: CheckStatus;
  results: IfraResult[];
  missing: MissingData[];
  warnings: string[];
}

/** Product family -> IFRA category through a mapping supplied as data; unmapped family = unknown (undefined). */
export function ifraCategoryForFamily(family: string, mapping: Readonly<Record<string, string>>): string | undefined {
  return Object.hasOwn(mapping, family) ? mapping[family] : undefined;
}

export function checkIfraCompliance(input: IfraInput): IfraReport {
  const policy = input.policy ?? {};
  const results: IfraResult[] = [];
  const bad = validFormulaLines(input.lines);
  for (const fragrance of input.fragrances) {
    const id = fragrance.materialId;
    const category = input.ifraCategory ?? null;
    const unknown = (reason: string, property: string): IfraResult => ({
      materialId: id,
      category,
      status: 'UNKNOWN',
      messageKey: IFRA_MESSAGE_KEY,
      params: { materialId: id, ...(category !== null && { category }) },
      missing: [{ materialId: id, property, reason }],
    });
    const lines = input.lines.filter((l) => l.materialId === id);
    if (bad === null && lines.length === 0) {
      results.push({ materialId: id, category, status: 'NOT_APPLICABLE', messageKey: IFRA_MESSAGE_KEY, params: { materialId: id }, missing: [] });
      continue;
    }
    if (bad !== null) {
      results.push(unknown(IFRA_REASONS.FORMULA_LINE_INVALID, 'percentWW'));
      continue;
    }
    if (category === null) {
      results.push(unknown(IFRA_REASONS.CATEGORY_MISSING, 'ifraCategory'));
      continue;
    }
    if (fragrance.certificate === undefined) {
      results.push(unknown(IFRA_REASONS.CERTIFICATE_MISSING, 'ifraCertificate'));
      continue;
    }
    const limit = Object.hasOwn(fragrance.certificate.limitsByCategory, category) ? fragrance.certificate.limitsByCategory[category] : undefined;
    if (limit === undefined) {
      results.push(unknown(IFRA_REASONS.LIMIT_MISSING_FOR_CATEGORY, 'ifraLimitPct'));
      continue;
    }
    const total = lines.reduce((acc, l) => acc.plus(l.percentWW), dec('0'));
    const oilPct = traced('add', `${id} in product (%)`, total, '%w/w', lines.map((l) => inputLeaf(`${id} line (%)`, l.percentWW, '%w/w')));
    const check = checkIfraLimit(oilPct, limit, policy, id);
    results.push({
      materialId: id,
      category,
      status: check.status,
      check,
      messageKey: IFRA_MESSAGE_KEY,
      params: {
        materialId: id,
        category,
        value: oilPct.value,
        ...(check.limitPct !== undefined && { limit: check.limitPct.value }),
        ...(check.marginPct !== undefined && { margin: check.marginPct.value }),
      },
      missing: check.missing,
    });
  }
  return {
    overall: overallStatus(results.map((r) => r.status)),
    results,
    missing: results.flatMap((r) => r.missing),
    warnings: results.flatMap((r) => r.check?.warnings ?? []),
  };
}

/** One IFRA limit on file for a fragrance oil (a value of its certificate), as the app stores it. */
export interface IfraLimitRow {
  id?: string;
  category: string;
  value?: string | null;
  unit: Unit;
  sourceId: string;
  confidence: Confidence;
  validFrom: string;
}

/**
 * The limits of a fragrance's certificate by category: for each category the one value `resolveValue` picks (best confidence, then
 * newest, then source id: always the same one for the same rows). A category with no value on file has no limit, so the check says UNKNOWN.
 */
export function ifraLimitsByCategory(rows: readonly IfraLimitRow[]): Record<string, SourcedScalar> {
  const withValue = rows.filter((row) => row.value !== null && row.value !== undefined);
  const limits: Record<string, SourcedScalar> = {};
  for (const category of [...new Set(withValue.map((row) => row.category))]) {
    const candidates = withValue
      .filter((row) => row.category === category)
      .map((row) => ({ _id: row.id, value: row.value as string, unit: row.unit, sourceId: row.sourceId, confidence: row.confidence, validFrom: row.validFrom }));
    const resolved = resolveValue('ifra_limit', candidates);
    if (resolved.ok && resolved.value.chosenBy === 'rule') {
      const { value, unit, sourceId, confidence, validFrom } = resolved.value.chosen;
      limits[category] = { value: value as string, unit, sourceId, confidence, validFrom };
    }
  }
  return limits;
}
