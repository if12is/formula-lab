/**
 * Compliance evaluator (N-01, N-05). Pure and deterministic.
 * Each rule gives PASS / FAIL / UNKNOWN (or NOT_APPLICABLE when its scope does not match). Missing data
 * is UNKNOWN, never PASS. A FAIL is still reported when the part of a concentration that IS known already
 * breaks an upper limit / a ban (unknown contributions can only add to it); no PASS is ever given on partial data.
 * Overall: FAIL if any FAIL, else UNKNOWN if any UNKNOWN, else PASS.
 */
import type { MissingData, SourcedValue } from '../contract';
import type { CheckStatus } from '../core/check-status';
import { dec, type Dec } from '../core/decimal';
import { isIsoDate } from '../core/fx';
import { parseDec, type SourcedScalar } from '../core/parse';
import { missing, ok, type Result } from '../core/result';
import { gateConfidence, type ConfidencePolicy } from '../trace/sourced';
import { decOf, inputLeaf, sourcedLeaf, traced, type Traced } from '../trace/trace';
import { substanceConcentration, validFormulaLines, COMPOSITION_REASONS, type Concentration, type FormulaComposition, type SubstanceSelector } from './composition';
import { hashRuleSet, validateRuleSet, type Rule, type RuleSet, type RuleTarget } from './dsl';

export type RuleStatus = CheckStatus | 'NOT_APPLICABLE';

export const COMPLIANCE_REASONS = {
  SCOPE_UNKNOWN: 'compliance.scope_unknown',
  RULE_PENDING_SOURCE: 'compliance.rule_pending_source',
  FIELD_MISSING: 'compliance.field_missing',
  FIELD_INVALID: 'compliance.field_invalid',
  RULESET_INVALID: 'compliance.ruleset_invalid',
} as const;

export const COMPLIANCE_WARNINGS = {
  RULESET_DRAFT: 'compliance.ruleset_draft',
  RULE_PENDING_SOURCE: 'compliance.rule_pending_source',
} as const;

export interface ProductContext {
  productType: string;
  /** Needed by rules whose scope has `rinseOff`; absent makes such a rule UNKNOWN. */
  rinseOff?: boolean;
  /** Needed by rules whose scope has `ifraCategory`. */
  ifraCategory?: string;
  /** Sourced product-level values for `field` targets, by field name. */
  fields?: Record<string, SourcedScalar>;
  policy?: ConfidencePolicy;
}

export interface RuleResult {
  ruleId: string;
  ruleVersion: string;
  status: RuleStatus;
  operator: Rule['operator'];
  target: RuleTarget;
  group?: string;
  /** Concentration / field value. When `valueComplete` is false it is only the known part (a lower bound). */
  value?: Traced;
  valueComplete: boolean;
  limit: string | null;
  unit: Rule['unit'];
  /** Distance to the limit (positive = room left); only when the value is complete. */
  margin?: Traced;
  sourceId: string | null;
  messageKey: string;
  params: Record<string, string>;
  missing: MissingData[];
}

export interface GroupResult {
  group: string;
  status: CheckStatus;
  /** sum(c_i / limit_i) over the applicable members (dimensionless); a lower bound when `complete` is false. */
  sumRatio: string;
  complete: boolean;
  members: { ruleId: string; ratio: string; complete: boolean }[];
}

export interface ComplianceReport {
  ruleSetId: string;
  ruleSetVersion: string;
  /** sha256 of the canonical JSON of the rule set, recorded with every evaluation. */
  ruleSetHash: string;
  overall: CheckStatus;
  results: RuleResult[];
  groups: GroupResult[];
  missing: MissingData[];
  warnings: string[];
}

export function overallStatus(statuses: readonly RuleStatus[]): CheckStatus {
  if (statuses.includes('FAIL')) {
    return 'FAIL';
  }
  return statuses.includes('UNKNOWN') ? 'UNKNOWN' : 'PASS';
}

function selectorOf(target: RuleTarget): SubstanceSelector | null {
  if (target.substanceCas !== undefined) {
    return { kind: 'cas', value: target.substanceCas };
  }
  if (target.inci !== undefined) {
    return { kind: 'inci', value: target.inci };
  }
  if (target.function !== undefined) {
    return { kind: 'function', value: target.function };
  }
  return null;
}

type Scope = 'in' | 'out' | 'unknown';

function scopeOf(rule: Rule, ctx: ProductContext): { scope: Scope; gaps: MissingData[] } {
  if (!rule.scope.productTypes.includes(ctx.productType)) {
    return { scope: 'out', gaps: [] };
  }
  const gaps: MissingData[] = [];
  let out = false;
  if (rule.scope.rinseOff !== undefined) {
    if (ctx.rinseOff === undefined) {
      gaps.push({ property: 'rinseOff', reason: COMPLIANCE_REASONS.SCOPE_UNKNOWN });
    } else if (ctx.rinseOff !== rule.scope.rinseOff) {
      out = true;
    }
  }
  if (rule.scope.ifraCategory !== undefined) {
    if (ctx.ifraCategory === undefined) {
      gaps.push({ property: 'ifraCategory', reason: COMPLIANCE_REASONS.SCOPE_UNKNOWN });
    } else if (ctx.ifraCategory !== rule.scope.ifraCategory) {
      out = true;
    }
  }
  if (out) {
    return { scope: 'out', gaps: [] };
  }
  return { scope: gaps.length > 0 ? 'unknown' : 'in', gaps };
}

interface Measured {
  pct: Traced;
  complete: boolean;
  missing: MissingData[];
  warnings: string[];
}

function measureField(name: string, rule: Rule, ctx: ProductContext): Measured {
  const zero = traced('input', `${name} (unknown)`, '0', rule.unit);
  const gap = (reason: string): Measured => ({ pct: zero, complete: false, missing: [{ property: name, reason }], warnings: [] });
  const sv = ctx.fields?.[name];
  if (sv === undefined) {
    return gap(COMPLIANCE_REASONS.FIELD_MISSING);
  }
  const value = parseDec(sv.value);
  if (value === null || (sv.unit !== undefined && sv.unit !== rule.unit) || !isIsoDate(sv.validFrom)) {
    return gap(COMPLIANCE_REASONS.FIELD_INVALID);
  }
  const normalized: SourcedValue = { ...sv, unit: rule.unit };
  const gated = gateConfidence(undefined, name, normalized, ctx.policy ?? {});
  if (!gated.ok) {
    return { pct: zero, complete: false, missing: gated.missing, warnings: [] };
  }
  return { pct: sourcedLeaf(name, normalized), complete: true, missing: [], warnings: gated.warnings };
}

function measureMaterial(id: string, formula: FormulaComposition): Measured {
  const bad = validFormulaLines(formula.lines);
  if (bad !== null) {
    return {
      pct: traced('add', `${id} in formula (%)`, '0', '%w/w'),
      complete: false,
      missing: [{ materialId: bad.materialId, property: 'percentWW', reason: COMPOSITION_REASONS.FORMULA_LINE_INVALID }],
      warnings: [],
    };
  }
  const lines = formula.lines.filter((l) => l.materialId === id);
  const total = lines.reduce((acc, l) => acc.plus(l.percentWW), dec('0'));
  return {
    pct: traced('add', `${id} in formula (%)`, total, '%w/w', lines.map((l) => inputLeaf(`${id} line (%)`, l.percentWW, '%w/w'))),
    complete: true,
    missing: [],
    warnings: [],
  };
}

function measure(rule: Rule, formula: FormulaComposition, ctx: ProductContext): Measured {
  if (rule.target.field !== undefined) {
    return measureField(rule.target.field, rule, ctx);
  }
  if (rule.target.materialId !== undefined) {
    return measureMaterial(rule.target.materialId, formula);
  }
  const sel = selectorOf(rule.target);
  /* c8 ignore next 3 -- unreachable for a validated rule set: exactly one target key. */
  if (sel === null) {
    throw new TypeError('evaluateCompliance: rule without target');
  }
  const c: Concentration = substanceConcentration(formula, sel, ctx.policy ?? {});
  return c;
}

function compare(op: Rule['operator'], v: Dec, limit: Dec | null): boolean {
  switch (op) {
    case '<=':
      return v.lte(limit as Dec);
    case '<':
      return v.lt(limit as Dec);
    case '>=':
      return v.gte(limit as Dec);
    case '>':
      return v.gt(limit as Dec);
    case '==':
      return v.eq(limit as Dec);
    case 'forbidden':
      return v.isZero();
    case 'required':
      return v.gt(0);
  }
}

/** Status from a measured value; `complete=false` means `v` is only a lower bound. */
function judge(op: Rule['operator'], v: Dec, limit: Dec | null, complete: boolean): CheckStatus {
  const upper = op === '<=' || op === '<' || op === 'forbidden';
  if (!complete) {
    // Unknown contributions are >= 0: a breached upper bound / present substance is already decided.
    if (upper && !compare(op, v, limit)) {
      return 'FAIL';
    }
    return 'UNKNOWN';
  }
  return compare(op, v, limit) ? 'PASS' : 'FAIL';
}

function marginOf(rule: Rule, value: Traced): Traced | undefined {
  if (rule.limit === null || rule.operator === 'forbidden' || rule.operator === 'required') {
    return undefined;
  }
  const v = decOf(value);
  const limit = dec(rule.limit);
  const limitLeaf = inputLeaf(`Limit of ${rule.id}`, rule.limit, rule.unit);
  if (rule.operator === '<=' || rule.operator === '<') {
    return traced('sub', 'Margin = limit - value', limit.minus(v), rule.unit, [limitLeaf, value]);
  }
  if (rule.operator === '>=' || rule.operator === '>') {
    return traced('sub', 'Margin = value - limit', v.minus(limit), rule.unit, [value, limitLeaf]);
  }
  return traced('sub', 'Margin = -|value - limit|', v.minus(limit).abs().neg(), rule.unit, [value, limitLeaf]);
}

function paramsOf(rule: Rule, value: Traced | undefined, complete: boolean, margin: Traced | undefined): Record<string, string> {
  const params: Record<string, string> = { ruleId: rule.id, unit: rule.unit };
  const t = rule.target;
  const subject = t.substanceCas ?? t.inci ?? t.function ?? t.materialId ?? t.field;
  if (subject !== undefined) {
    params.target = subject;
  }
  if (rule.limit !== null) {
    params.limit = rule.limit;
  }
  if (value !== undefined) {
    params.value = value.value;
    params.valueComplete = complete ? 'true' : 'false';
  }
  if (margin !== undefined) {
    params.margin = margin.value;
  }
  return params;
}

function baseResult(rule: Rule, status: RuleStatus): RuleResult {
  return {
    ruleId: rule.id,
    ruleVersion: rule.version,
    status,
    operator: rule.operator,
    target: rule.target,
    ...(rule.group !== undefined && { group: rule.group }),
    valueComplete: false,
    limit: rule.limit,
    unit: rule.unit,
    sourceId: rule.source?.sourceId ?? null,
    messageKey: rule.messageKey,
    params: { ruleId: rule.id },
    missing: [],
  };
}

/**
 * Evaluates a rule set against a formula. A rule set that fails validation gives `missing`, never a report.
 * Rules are evaluated in rule-set order; the report is a pure function of (formula, rule set, context).
 */
export function evaluateCompliance(
  formula: FormulaComposition,
  ruleSetInput: RuleSet,
  context: ProductContext,
): Result<ComplianceReport> {
  const validated = validateRuleSet(ruleSetInput);
  if (!validated.ok) {
    return missing(
      validated.missing.map((m) => ({ property: `ruleSet${m.property.slice(1)}`, reason: `${COMPLIANCE_REASONS.RULESET_INVALID}:${m.reason}` })),
    );
  }
  const ruleSet = validated.value;
  const warnings: string[] = ruleSet.status === 'draft' ? [COMPLIANCE_WARNINGS.RULESET_DRAFT] : [];
  const results: RuleResult[] = [];
  const members = new Map<string, { result: RuleResult; ratio: Dec; complete: boolean; ratioId: string }[]>();

  for (const rule of ruleSet.rules) {
    const { scope, gaps } = scopeOf(rule, context);
    if (scope === 'out') {
      results.push(baseResult(rule, 'NOT_APPLICABLE'));
      continue;
    }
    if (scope === 'unknown') {
      results.push({ ...baseResult(rule, 'UNKNOWN'), missing: gaps });
      continue;
    }
    const m = measure(rule, formula, context);
    warnings.push(...m.warnings);
    const limit = rule.limit === null ? null : dec(rule.limit);
    const value = decOf(m.pct);
    const result: RuleResult = {
      ...baseResult(rule, judge(rule.operator, value, limit, m.complete)),
      value: m.pct,
      valueComplete: m.complete,
      missing: m.missing,
    };
    if (m.complete) {
      const margin = marginOf(rule, m.pct);
      if (margin !== undefined) {
        result.margin = margin;
      }
    }
    result.params = paramsOf(rule, m.pct, m.complete, result.margin);
    if (rule.source === null) {
      // pendingSource (draft only): a rule with no source can never support a PASS.
      warnings.push(`${COMPLIANCE_WARNINGS.RULE_PENDING_SOURCE}:${rule.id}`);
      if (result.status === 'PASS') {
        result.status = 'UNKNOWN';
        result.missing = [...result.missing, { property: 'ruleSource', reason: COMPLIANCE_REASONS.RULE_PENDING_SOURCE }];
      }
    }
    if (rule.group !== undefined && limit !== null) {
      const list = members.get(rule.group) ?? [];
      list.push({ result, ratio: value.div(limit), complete: m.complete, ratioId: rule.id });
      members.set(rule.group, list);
    }
    results.push(result);
  }

  const groups: GroupResult[] = [];
  for (const [group, list] of [...members.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))) {
    const sum = list.reduce((acc, x) => acc.plus(x.ratio), dec('0'));
    const complete = list.every((x) => x.complete);
    let status: CheckStatus = complete ? (sum.lte(1) ? 'PASS' : 'FAIL') : sum.gt(1) ? 'FAIL' : 'UNKNOWN';
    if (status === 'PASS' && list.some((x) => x.result.missing.some((g) => g.reason === COMPLIANCE_REASONS.RULE_PENDING_SOURCE))) {
      status = 'UNKNOWN';
    }
    for (const x of list) {
      x.result.status = status;
      x.result.params = { ...x.result.params, group, groupSumRatio: sum.toFixed() };
    }
    groups.push({
      group,
      status,
      sumRatio: sum.toFixed(),
      complete,
      members: list.map((x) => ({ ruleId: x.ratioId, ratio: x.ratio.toFixed(), complete: x.complete })),
    });
  }

  const allMissing = results.flatMap((r) => r.missing);
  return ok({
    ruleSetId: ruleSet.id,
    ruleSetVersion: ruleSet.version,
    ruleSetHash: hashRuleSet(ruleSet),
    overall: overallStatus(results.map((r) => r.status)),
    results,
    groups,
    missing: allMissing,
    warnings,
  });
}
