/**
 * Substance concentration in the finished product (N-01): formula lines x sourced trade-material
 * composition. Nothing is assumed: a material without a (valid, sufficiently trusted) composition, or a
 * composition that cannot rule a substance in or out, makes that concentration UNKNOWN. The value
 * returned for an unknown concentration is only the part that is known, i.e. a lower bound.
 */
import type { Confidence, MissingData, SourcedValue } from '../contract';
import { dec, isDecimalString } from '../core/decimal';
import { isIsoDate } from '../core/fx';
import { gateConfidence, type ConfidencePolicy } from '../trace/sourced';
import { inputLeaf, sourcedLeaf, traced, type Traced } from '../trace/trace';

export const COMPOSITION_REASONS = {
  MISSING: 'compliance.composition_missing',
  INVALID: 'compliance.composition_invalid',
  NOT_DATED: 'compliance.composition_not_dated',
  INCOMPLETE: 'compliance.composition_incomplete',
  AMBIGUOUS: 'compliance.composition_ambiguous_identifier',
  FORMULA_LINE_INVALID: 'compliance.formula_line_invalid',
} as const;

export interface CompositionComponent {
  cas?: string;
  inci?: string;
  /** Functions of the component (CosIng style); needed only for `function` targets. */
  functions?: string[];
  /** % w/w of the trade material. */
  pctWW: string;
}

export interface SourcedComposition {
  components: CompositionComponent[];
  /** true: the list is the whole material, so a substance that is not listed is absent (0%). */
  complete: boolean;
  sourceId: string;
  confidence: Confidence;
  /** ISO date. */
  validFrom: string;
}

export interface MaterialComposition {
  materialId: string;
  /** Absent = composition unknown. */
  composition?: SourcedComposition;
}

export interface ComplianceLine {
  materialId: string;
  /** % w/w of the finished product. */
  percentWW: string;
}

export interface FormulaComposition {
  lines: ComplianceLine[];
  materials: MaterialComposition[];
}

export type SubstanceSelector =
  | { kind: 'cas'; value: string }
  | { kind: 'inci'; value: string }
  | { kind: 'function'; value: string };

export interface Concentration {
  /** Sum of the known contributions, % w/w of the finished product; a lower bound when `complete` is false. */
  pct: Traced;
  complete: boolean;
  missing: MissingData[];
  warnings: string[];
}

export function normalizeInci(name: string): string {
  return name.trim().toUpperCase();
}

type Match = 'match' | 'no' | 'ambiguous';

function matchComponent(c: CompositionComponent, sel: SubstanceSelector): Match {
  switch (sel.kind) {
    case 'cas':
      return c.cas === undefined ? 'ambiguous' : c.cas === sel.value ? 'match' : 'no';
    case 'inci':
      return c.inci === undefined ? 'ambiguous' : normalizeInci(c.inci) === normalizeInci(sel.value) ? 'match' : 'no';
    case 'function':
      return c.functions === undefined ? 'ambiguous' : c.functions.includes(sel.value) ? 'match' : 'no';
  }
}

/** Why a composition cannot be used, or null when it is usable. Also applies the confidence policy. */
export function compositionProblem(
  materialId: string,
  comp: SourcedComposition | undefined,
  policy: ConfidencePolicy,
): { reason: string | null; warnings: string[] } {
  if (comp === undefined) {
    return { reason: COMPOSITION_REASONS.MISSING, warnings: [] };
  }
  if (!isIsoDate(comp.validFrom)) {
    return { reason: COMPOSITION_REASONS.NOT_DATED, warnings: [] };
  }
  let total = dec('0');
  for (const c of comp.components) {
    const hasId = (c.cas !== undefined && c.cas.trim() !== '') || (c.inci !== undefined && c.inci.trim() !== '');
    if (!hasId || !isDecimalString(c.pctWW) || dec(c.pctWW).lt(0) || dec(c.pctWW).gt(100)) {
      return { reason: COMPOSITION_REASONS.INVALID, warnings: [] };
    }
    total = total.plus(c.pctWW);
  }
  if (total.gt(100)) {
    return { reason: COMPOSITION_REASONS.INVALID, warnings: [] };
  }
  const gated = gateConfidence(
    materialId,
    'composition',
    { value: '0', unit: '%w/w', sourceId: comp.sourceId, confidence: comp.confidence, validFrom: comp.validFrom },
    policy,
  );
  return gated.ok ? { reason: null, warnings: gated.warnings } : { reason: gated.missing[0]?.reason ?? COMPOSITION_REASONS.INVALID, warnings: [] };
}

export function validFormulaLines(lines: readonly ComplianceLine[]): ComplianceLine | null {
  return lines.find((l) => !isDecimalString(l.percentWW) || dec(l.percentWW).lt(0) || dec(l.percentWW).gt(100)) ?? null;
}

function leafOf(materialId: string, label: string, pct: string, comp: SourcedComposition): Traced {
  const sv: SourcedValue = { value: pct, unit: '%w/w', sourceId: comp.sourceId, confidence: comp.confidence, validFrom: comp.validFrom };
  return sourcedLeaf(`${materialId}: ${label} (% in material)`, sv);
}

/** Concentration (% w/w of the finished product) of the selected substance(s). */
export function substanceConcentration(
  formula: FormulaComposition,
  selector: SubstanceSelector,
  policy: ConfidencePolicy = {},
): Concentration {
  const label = `${selector.kind} ${selector.value}`;
  const bad = validFormulaLines(formula.lines);
  if (bad !== null) {
    return {
      pct: traced('add', `${label} in product (%)`, '0', '%w/w'),
      complete: false,
      missing: [{ materialId: bad.materialId, property: 'percentWW', reason: COMPOSITION_REASONS.FORMULA_LINE_INVALID }],
      warnings: [],
    };
  }
  const byId = new Map<string, MaterialComposition>();
  for (const m of formula.materials) {
    if (!byId.has(m.materialId)) {
      byId.set(m.materialId, m);
    }
  }
  const parts: Traced[] = [];
  const gaps: MissingData[] = [];
  const warnings: string[] = [];
  let total = dec('0');
  for (const line of formula.lines) {
    const linePct = dec(line.percentWW);
    if (linePct.isZero()) {
      continue;
    }
    const comp = byId.get(line.materialId)?.composition;
    const problem = compositionProblem(line.materialId, comp, policy);
    if (problem.reason !== null || comp === undefined) {
      gaps.push({ materialId: line.materialId, property: 'composition', reason: problem.reason ?? COMPOSITION_REASONS.MISSING });
      continue;
    }
    warnings.push(...problem.warnings);
    const matches = comp.components.filter((c) => matchComponent(c, selector) === 'match');
    const ambiguous = comp.components.some((c) => matchComponent(c, selector) === 'ambiguous');
    const lineLeaf = inputLeaf(`${line.materialId} in formula (%)`, line.percentWW, '%w/w');
    for (const c of matches) {
      const contribution = linePct.mul(c.pctWW).div(100);
      total = total.plus(contribution);
      parts.push(
        traced('mul', `${line.materialId} x ${label} / 100`, contribution, '%w/w', [lineLeaf, leafOf(line.materialId, label, c.pctWW, comp)]),
      );
    }
    if (ambiguous) {
      gaps.push({ materialId: line.materialId, property: 'composition', reason: COMPOSITION_REASONS.AMBIGUOUS });
    } else if (matches.length === 0 || selector.kind === 'function') {
      if (!comp.complete) {
        gaps.push({ materialId: line.materialId, property: 'composition', reason: COMPOSITION_REASONS.INCOMPLETE });
      }
    }
  }
  return {
    pct: traced('add', `${label} in product (%)`, total, '%w/w', parts),
    complete: gaps.length === 0,
    missing: gaps,
    warnings,
  };
}
