/**
 * INCI list generator (N-06). Ingredients are listed in descending order of concentration in the finished
 * product (deterministic: ties by INCI name, plain code-unit order). Below 1% the order is free by the
 * labelling rule, so the 1% boundary is marked and the same deterministic order is kept after it.
 * The same INCI coming from several materials is merged. A material whose composition is unknown or
 * incomplete (or lacks an INCI name) makes the list incomplete, which is reported as `missing`.
 * Label warnings: a rule with `labelWarningKey` adds that key when its substance is present.
 */
import type { MissingData } from '../contract';
import { dec, type Dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, inputLeaf, sourcedLeaf, traced, type Traced } from '../trace/trace';
import {
  compositionProblem,
  COMPOSITION_REASONS,
  normalizeInci,
  substanceConcentration,
  validFormulaLines,
  type FormulaComposition,
} from './composition';
import { validateRuleSet, type RuleSet } from './dsl';
import { COMPLIANCE_REASONS, type ProductContext } from './evaluate';

export const INCI_REASONS = {
  NAME_MISSING: 'inci.name_missing',
  COMPOSITION_MISSING: 'inci.composition_missing',
  COMPOSITION_INCOMPLETE: 'inci.composition_incomplete',
  COMPOSITION_INVALID: 'inci.composition_invalid',
  LABEL_WARNING_UNDECIDABLE: 'inci.label_warning_undecidable',
  FORMULA_LINE_INVALID: 'inci.formula_line_invalid',
} as const;

export interface InciItem {
  /** Normalised (trimmed, upper-case) INCI name. */
  inci: string;
  /** % w/w of the finished product. */
  pct: Traced;
  /** true below 1%: the label order is free from here on (the list keeps the deterministic order). */
  belowOnePct: boolean;
}

export interface LabelWarning {
  key: string;
  ruleId: string;
  /** CAS or INCI the rule targets. */
  substance: string;
  pct: Traced;
  sourceId: string | null;
}

export interface InciList {
  items: InciItem[];
  /** Index of the first item below 1%, or null when every item is >= 1%. */
  belowOnePctStartIndex: number | null;
  labelWarnings: LabelWarning[];
}

export interface InciOptions {
  /** Both together: the label-warning hook needs the product type to apply rule scopes. */
  ruleSet?: RuleSet;
  productContext?: ProductContext;
  policy?: ConfidencePolicy;
}

function byPctThenName(a: { inci: string; v: Dec }, b: { inci: string; v: Dec }): number {
  const c = b.v.cmp(a.v);
  return c !== 0 ? c : a.inci < b.inci ? -1 : a.inci > b.inci ? 1 : 0;
}

export function buildInciList(formula: FormulaComposition, options: InciOptions = {}): Result<InciList> {
  const policy = options.policy ?? options.productContext?.policy ?? {};
  const bad = validFormulaLines(formula.lines);
  if (bad !== null) {
    return missing([{ materialId: bad.materialId, property: 'percentWW', reason: INCI_REASONS.FORMULA_LINE_INVALID }]);
  }
  const byId = new Map(formula.materials.map((m) => [m.materialId, m] as const).reverse());
  const gaps: MissingData[] = [];
  const warnings: string[] = [];
  const merged = new Map<string, { v: Dec; parts: Traced[] }>();

  for (const line of formula.lines) {
    const linePct = dec(line.percentWW);
    if (linePct.isZero()) {
      continue;
    }
    const comp = byId.get(line.materialId)?.composition;
    const problem = compositionProblem(line.materialId, comp, policy);
    if (problem.reason !== null || comp === undefined) {
      const reason = problem.reason === COMPOSITION_REASONS.MISSING ? INCI_REASONS.COMPOSITION_MISSING : INCI_REASONS.COMPOSITION_INVALID;
      gaps.push({ materialId: line.materialId, property: 'composition', reason });
      continue;
    }
    warnings.push(...problem.warnings);
    if (!comp.complete) {
      gaps.push({ materialId: line.materialId, property: 'composition', reason: INCI_REASONS.COMPOSITION_INCOMPLETE });
      continue;
    }
    const lineLeaf = inputLeaf(`${line.materialId} in formula (%)`, line.percentWW, '%w/w');
    for (const c of comp.components) {
      if (c.inci === undefined || c.inci.trim() === '') {
        gaps.push({ materialId: line.materialId, property: 'composition', reason: INCI_REASONS.NAME_MISSING });
        continue;
      }
      const name = normalizeInci(c.inci);
      const contribution = linePct.mul(c.pctWW).div(100);
      const leaf = sourcedLeaf(`${line.materialId}: ${name} (% in material)`, {
        value: c.pctWW,
        unit: '%w/w',
        sourceId: comp.sourceId,
        confidence: comp.confidence,
        validFrom: comp.validFrom,
      });
      const entry = merged.get(name) ?? { v: dec('0'), parts: [] };
      entry.v = entry.v.plus(contribution);
      entry.parts.push(traced('mul', `${line.materialId} x ${name} / 100`, contribution, '%w/w', [lineLeaf, leaf]));
      merged.set(name, entry);
    }
  }
  if (gaps.length > 0) {
    return missing(gaps, warnings);
  }

  const sorted = [...merged.entries()]
    .filter(([, e]) => e.v.gt(0))
    .map(([inci, e]) => ({ inci, v: e.v, parts: e.parts }))
    .sort(byPctThenName);
  const items: InciItem[] = sorted.map((e) => ({
    inci: e.inci,
    pct: traced('add', `${e.inci} in product (%)`, e.v, '%w/w', e.parts),
    belowOnePct: e.v.lt(1),
  }));
  const boundary = items.findIndex((i) => i.belowOnePct);

  const labelWarnings: LabelWarning[] = [];
  if (options.ruleSet !== undefined && options.productContext !== undefined) {
    const validated = validateRuleSet(options.ruleSet);
    if (!validated.ok) {
      return missing(
        validated.missing.map((m) => ({ property: `ruleSet${m.property.slice(1)}`, reason: `${COMPLIANCE_REASONS.RULESET_INVALID}:${m.reason}` })),
      );
    }
    for (const rule of validated.value.rules) {
      if (rule.labelWarningKey === undefined || !rule.scope.productTypes.includes(options.productContext.productType)) {
        continue;
      }
      const sel = rule.target.substanceCas !== undefined ? ({ kind: 'cas', value: rule.target.substanceCas } as const) : ({ kind: 'inci', value: rule.target.inci ?? '' } as const);
      const c = substanceConcentration(formula, sel, policy);
      if (!c.complete && decOf(c.pct).isZero()) {
        // Presence cannot be decided (e.g. a CAS-targeted rule vs components named only by INCI): never skip silently.
        return missing([{ property: rule.id, reason: INCI_REASONS.LABEL_WARNING_UNDECIDABLE }], warnings);
      }
      if (decOf(c.pct).gt(0)) {
        labelWarnings.push({ key: rule.labelWarningKey, ruleId: rule.id, substance: sel.value, pct: c.pct, sourceId: rule.source?.sourceId ?? null });
      }
    }
  }
  return ok({ items, belowOnePctStartIndex: boundary === -1 ? null : boundary, labelWarnings }, warnings);
}
