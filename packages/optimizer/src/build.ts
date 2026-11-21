/**
 * J-04: LP builder.
 *
 * Model (variables are percent w/w of the finished formula, so they sum to 100):
 *
 *   minimize    Σ (Pᵢ/100) · xᵢ                         cost, EGP per kg of formula
 *   subject to  Σ xᵢ = 100                              (row `sum`, structural)
 *               Σ (aᵢ/100) · xᵢ  >= totalActiveMinPct   (`active.min`)   aᵢ = active matter %, 0..100
 *               Σ (aᵢ/100) · xᵢ  <= totalActiveMaxPct   (`active.max`)
 *               Σ_{i tagged f} xᵢ >= / <= bound         (`fn.<f>.min|max`)
 *               Σ (Pᵢ/100) · xᵢ  <= maxCostEGPperKg     (`maxCost`)
 *               Σ (Pᵢ/100) · xᵢ  <= cap from target retail price (`targetPrice`, via reverse.ts)
 *               xᵢ >= min, xᵢ <= max                    (`mat.<id>.min|max`; required materials need min > 0)
 *               xᵢ >= 0
 *   forbidden materials get no variable (x = 0).
 *
 * Variables are named `x0000`, `x0001`, ... by the rank of the materialId (code-unit order), rows `r00`, ...
 * in a fixed order, so the LP text is a pure function of the data and independent of input order.
 *
 * Numeric policy: every coefficient / bound is built from exact Decimal strings (no float formatting).
 * HiGHS reads them as IEEE doubles and solves in doubles, so its answer is NOT trusted as is:
 * round.ts rounds the solution to the engine percent step (0.001 %), re-balances through the declared
 * q.s. material, and verifies the rounded point against every constraint with exact Decimal arithmetic.
 */
import type { Dec } from '@fl/engine';
import type { TierProfile } from './profile';
import { byCodeUnit, ceilToGrid, floorToGrid, D, dec, str, HALF_STEP, PERCENT_STEP } from './util';

export interface BuildMaterial {
  materialId: string;
  /** EGP per kg, validated and gated by the caller. */
  pricePerKg: Dec;
  /** Active matter, percent w/w 0..100. */
  activePct: Dec;
  functions: readonly string[];
}

export interface Variable {
  index: number;
  name: string;
  materialId: string;
  pricePerKg: Dec;
  activePct: Dec;
  functions: readonly string[];
  /** Lower bound of the LP column (0, or the q.s. margin in the safe-rounding attempt). */
  lower: Dec;
}

export interface Term {
  v: number;
  coef: Dec;
}

export interface ConstraintMessage {
  /** Message key, `optimizer.infeasible.*`; the UI maps it to Arabic (see messages.ts). */
  key: string;
  params: Record<string, string>;
}

export type Sense = '=' | '>=' | '<=';

export interface Row {
  index: number;
  /** LP row name, `r00`... */
  name: string;
  /** Stable constraint id, e.g. `active.min`, `fn.anionic_surfactant.max`, `mat.LABSA.min`. */
  id: string;
  sense: Sense;
  rhs: Dec;
  terms: Term[];
  /** Structural rows (the sum) are never part of a conflict set. */
  structural: boolean;
  message: ConstraintMessage | null;
}

export interface Model {
  variables: Variable[];
  rows: Row[];
  /** Index of the declared q.s. variable, or null. */
  qsIndex: number | null;
}

export interface BuildSpec {
  profile: TierProfile;
  materials: readonly BuildMaterial[];
  /** Declared q.s. (balancing) material id, or null. */
  qsMaterialId: string | null;
  /** Max formula cost EGP/kg derived from the target retail price (reverse.ts), or null. */
  targetCostCapPerKg: Dec | null;
}

const pad = (n: number, width: number): string => String(n).padStart(width, '0');

/** Builds the exact (untightened) model. Inputs are assumed validated by optimize(). */
export function buildModel(spec: BuildSpec): Model {
  const forbidden = new Set(spec.profile.constraints.forbiddenMaterials);
  const required = new Set(spec.profile.constraints.requiredMaterials);
  const usable = spec.materials.filter((m) => !forbidden.has(m.materialId)).sort((a, b) => byCodeUnit(a.materialId, b.materialId));
  const variables: Variable[] = usable.map((m, index) => ({
    index,
    name: `x${pad(index, 4)}`,
    materialId: m.materialId,
    pricePerKg: m.pricePerKg,
    activePct: m.activePct,
    functions: [...m.functions].sort(byCodeUnit),
    lower: dec('0'),
  }));
  const qsIndex = spec.qsMaterialId === null ? null : (variables.find((v) => v.materialId === spec.qsMaterialId)?.index ?? null);

  const rows: Row[] = [];
  const addRow = (id: string, sense: Sense, rhs: Dec, terms: Term[], message: ConstraintMessage | null, structural = false): void => {
    const index = rows.length;
    rows.push({ index, name: `r${pad(index, 2)}`, id, sense, rhs, terms, structural, message });
  };

  const k = spec.profile.constraints;
  addRow('sum', '=', dec('100'), variables.map((v) => ({ v: v.index, coef: dec('1') })), null, true);

  const activeTerms = variables.filter((v) => !v.activePct.isZero()).map((v) => ({ v: v.index, coef: v.activePct.div(100) }));
  if (k.totalActiveMinPct !== null) {
    addRow('active.min', '>=', dec(k.totalActiveMinPct), activeTerms, { key: 'optimizer.infeasible.active_min_too_high', params: { limitPct: k.totalActiveMinPct } });
  }
  if (k.totalActiveMaxPct !== null) {
    addRow('active.max', '<=', dec(k.totalActiveMaxPct), activeTerms, { key: 'optimizer.infeasible.active_max_too_low', params: { limitPct: k.totalActiveMaxPct } });
  }

  for (const fb of [...k.functionBounds].sort((a, b) => byCodeUnit(a.function, b.function))) {
    const terms = variables.filter((v) => v.functions.includes(fb.function)).map((v) => ({ v: v.index, coef: dec('1') }));
    if (fb.min !== null) {
      addRow(`fn.${fb.function}.min`, '>=', dec(fb.min), terms, { key: 'optimizer.infeasible.function_min_too_high', params: { function: fb.function, limitPct: fb.min } });
    }
    if (fb.max !== null) {
      addRow(`fn.${fb.function}.max`, '<=', dec(fb.max), terms, { key: 'optimizer.infeasible.function_max_too_low', params: { function: fb.function, limitPct: fb.max } });
    }
  }

  const costTerms = variables.filter((v) => !v.pricePerKg.isZero()).map((v) => ({ v: v.index, coef: v.pricePerKg.div(100) }));
  if (k.maxCostEGPperKg !== null) {
    addRow('maxCost', '<=', dec(k.maxCostEGPperKg), costTerms, { key: 'optimizer.infeasible.max_cost_too_low', params: { limitEGPperKg: k.maxCostEGPperKg } });
  }
  if (spec.targetCostCapPerKg !== null) {
    addRow('targetPrice', '<=', spec.targetCostCapPerKg, costTerms, { key: 'optimizer.infeasible.target_price_too_low', params: { limitEGPperKg: str(spec.targetCostCapPerKg) } });
  }

  for (const mb of [...k.materialBounds].sort((a, b) => byCodeUnit(a.materialId, b.materialId))) {
    const v = variables.find((x) => x.materialId === mb.materialId);
    if (v === undefined) {
      continue; // forbidden or not offered; optimize() reports a required-but-absent material as missing
    }
    const isRequired = required.has(mb.materialId) ? 'true' : 'false';
    if (mb.min !== null) {
      addRow(`mat.${mb.materialId}.min`, '>=', dec(mb.min), [{ v: v.index, coef: dec('1') }], { key: 'optimizer.infeasible.material_min_too_high', params: { materialId: mb.materialId, limitPct: mb.min, required: isRequired } });
    }
    if (mb.max !== null) {
      addRow(`mat.${mb.materialId}.max`, '<=', dec(mb.max), [{ v: v.index, coef: dec('1') }], { key: 'optimizer.infeasible.material_max_too_low', params: { materialId: mb.materialId, limitPct: mb.max, required: isRequired } });
    }
  }
  return { variables, rows, qsIndex };
}

/** Objective (cost, EGP/kg of formula) terms of a model. */
export function costObjective(model: Model): Term[] {
  return model.variables.filter((v) => !v.pricePerKg.isZero()).map((v) => ({ v: v.index, coef: v.pricePerKg.div(100) }));
}

export interface ExtraRow {
  sense: Sense;
  rhs: Dec;
  terms: Term[];
}

export interface RenderOptions {
  objective: Term[];
  extraRows?: readonly ExtraRow[];
  /** Row indexes to leave out (conflict-set search). Structural rows can't be omitted. */
  omitRows?: ReadonlySet<number>;
}

function termsText(model: Model, terms: readonly Term[]): string {
  if (terms.length === 0) {
    // An empty row means "0 >= something": keep it in the model through an explicit zero coefficient.
    return `0 ${model.variables[0]?.name ?? 'x0000'}`;
  }
  return terms
    .map((t, i) => {
      const neg = t.coef.isNegative();
      const mag = t.coef.abs().toFixed();
      const name = model.variables[t.v]?.name ?? '';
      if (i === 0) {
        return `${neg ? '-' : ''}${mag} ${name}`;
      }
      return `${neg ? '-' : '+'} ${mag} ${name}`;
    })
    .join(' ');
}

export interface LpRow {
  /** `r<NN>` for model rows, `e<NNN>` for extra rows. */
  name: string;
  sense: Sense;
  rhs: Dec;
  terms: readonly Term[];
}

/** The rows actually sent to the solver: model rows minus omitted ones, then extra rows. */
export function lpRows(model: Model, options: Pick<RenderOptions, 'extraRows' | 'omitRows'>): LpRow[] {
  const rows: LpRow[] = [];
  for (const row of model.rows) {
    if (options.omitRows?.has(row.index) === true && !row.structural) {
      continue;
    }
    rows.push({ name: row.name, sense: row.sense, rhs: row.rhs, terms: row.terms });
  }
  (options.extraRows ?? []).forEach((row, i) => {
    rows.push({ name: `e${pad(i, 3)}`, sense: row.sense, rhs: row.rhs, terms: row.terms });
  });
  return rows;
}

/**
 * CPLEX LP text, exact decimal strings only. Rows keep their `r<NN>` names; extra rows are `e<NNN>`.
 */
export function renderLp(model: Model, options: RenderOptions): string {
  const lines: string[] = ['Minimize', ` obj: ${termsText(model, options.objective)}`, 'Subject To'];
  for (const row of lpRows(model, options)) {
    lines.push(` ${row.name}: ${termsText(model, row.terms)} ${row.sense} ${row.rhs.toFixed()}`);
  }
  lines.push('Bounds');
  for (const v of model.variables) {
    lines.push(` ${v.lower.toFixed()} <= ${v.name} <= 100`);
  }
  lines.push('End');
  return `${lines.join('\n')}\n`;
}

/**
 * Safe-rounding variant of a model. Final percents are rounded to PERCENT_STEP, so (a) bounds on a single
 * non-q.s. variable are moved to the grid (loses no grid point), and optionally (b) every inequality is
 * tightened by the worst possible rounding error of its left-hand side, which makes ANY nearest-rounded
 * (and q.s.-rebalanced) point of the tightened model feasible for the original one:
 *
 *   with q.s. variable q: |Δ lhs| <= 0.0005 · Σ_{i≠q} |cᵢ − c_q|   (non-q.s. variables move by <= half a step,
 *                                                                    q.s. absorbs the opposite sum)
 *   without q.s.:         |Δ lhs| <= 0.001  · Σ |cᵢ|              (largest-remainder rounding moves a value by < 1 step)
 *
 * and the q.s. column gets lower bound 0.0005·(n−1) so it stays >= 0.
 */
export function tightenForRounding(model: Model, safetyMargin: boolean): Model {
  const q = model.qsIndex;
  const coefOf = (row: Row, v: number): Dec => row.terms.find((t) => t.v === v)?.coef ?? dec('0');
  const rows = model.rows.map((row): Row => {
    if (row.structural || row.sense === '=') {
      return row;
    }
    let rhs = row.rhs;
    const single = row.terms.length === 1 ? row.terms[0] : undefined;
    if (single !== undefined && single.coef.eq(1) && single.v !== q) {
      rhs = row.sense === '>=' ? ceilToGrid(rhs) : floorToGrid(rhs);
    }
    if (safetyMargin) {
      let margin = dec('0');
      if (q === null) {
        for (const t of row.terms) {
          margin = margin.plus(t.coef.abs());
        }
        margin = margin.mul(PERCENT_STEP);
      } else {
        const cq = coefOf(row, q);
        for (const v of model.variables) {
          if (v.index !== q) {
            margin = margin.plus(coefOf(row, v.index).minus(cq).abs());
          }
        }
        margin = margin.mul(HALF_STEP);
      }
      rhs = row.sense === '>=' ? rhs.plus(margin) : rhs.minus(margin);
    }
    return { ...row, rhs };
  });
  const variables = model.variables.map((v): Variable =>
    safetyMargin && v.index === q ? { ...v, lower: new D(HALF_STEP).mul(model.variables.length - 1) } : v,
  );
  return { ...model, rows, variables };
}
