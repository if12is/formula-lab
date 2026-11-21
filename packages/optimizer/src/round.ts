/**
 * Post-processing of a solver solution (J-04): round to the engine percent step, re-balance, and verify
 * EXACTLY. HiGHS works in IEEE doubles, so its point is only a candidate; the answer reported to users
 * is the rounded Decimal point that passed verify() against every constraint of the exact model.
 */
import type { Dec } from '@fl/engine';
import type { Model } from './build';
import { D, dec, nearestOnGrid, PERCENT_STEP } from './util';

/**
 * Rounds each percent to PERCENT_STEP (half-even). With a declared q.s. material it takes
 * 100 − Σ(others) so the sum is exactly 100. Without one, largest-remainder rounding restores the sum
 * (ties by variable order), moving a value by at most one step.
 */
export function roundSolution(model: Model, raw: readonly Dec[]): Dec[] {
  const rounded = raw.map((x) => nearestOnGrid(x));
  const total = rounded.reduce((a, b) => a.plus(b), dec('0'));
  const hundred = dec('100');
  if (model.qsIndex !== null) {
    const others = total.minus(rounded[model.qsIndex] ?? dec('0'));
    rounded[model.qsIndex] = hundred.minus(others);
    return rounded;
  }
  const residual = hundred.minus(total);
  if (residual.isZero()) {
    return rounded;
  }
  const up = residual.gt(0);
  let steps = residual.abs().div(PERCENT_STEP).toNumber();
  // Candidates ordered by how far rounding pushed them against the needed direction, then by index.
  const order = rounded
    .map((r, i) => ({ i, gap: up ? (raw[i] ?? r).minus(r) : r.minus(raw[i] ?? r), r }))
    .filter((c) => up || c.r.gte(PERCENT_STEP))
    .sort((a, b) => b.gap.comparedTo(a.gap) || a.i - b.i);
  if (order.length === 0) {
    return rounded;
  }
  for (let k = 0; steps > 0; k = (k + 1) % order.length, steps -= 1) {
    const c = order[k];
    if (c !== undefined) {
      rounded[c.i] = up ? (rounded[c.i] ?? c.r).plus(PERCENT_STEP) : (rounded[c.i] ?? c.r).minus(PERCENT_STEP);
    }
  }
  return rounded;
}

export interface RowCheck {
  id: string;
  sense: '=' | '>=' | '<=';
  lhs: Dec;
  rhs: Dec;
  /** >= 0 when satisfied (0 = binding, exactly); negative = violated by that amount. */
  slack: Dec;
}

export interface Verification {
  ok: boolean;
  rows: RowCheck[];
  violations: RowCheck[];
}

/** Exact Decimal check of a point against every row and x >= 0. */
export function verify(model: Model, values: readonly Dec[]): Verification {
  const rows: RowCheck[] = [];
  for (const row of model.rows) {
    let lhs = dec('0');
    for (const t of row.terms) {
      lhs = lhs.plus(t.coef.mul(values[t.v] ?? dec('0')));
    }
    const diff = lhs.minus(row.rhs);
    const slack = row.sense === '>=' ? diff : row.sense === '<=' ? diff.neg() : diff.abs().neg();
    rows.push({ id: row.id, sense: row.sense, lhs, rhs: row.rhs, slack });
  }
  for (const v of model.variables) {
    const x = values[v.index] ?? dec('0');
    rows.push({ id: `nonneg.${v.materialId}`, sense: '>=', lhs: x, rhs: dec('0'), slack: x });
  }
  const violations = rows.filter((r) => r.slack.isNegative() && !r.slack.isZero());
  return { ok: violations.length === 0, rows, violations };
}

/** Exact cost EGP per kg of formula: Σ xᵢ/100 · Pᵢ. */
export function exactCostPerKg(model: Model, values: readonly Dec[]): Dec {
  let cost = new D(0);
  for (const v of model.variables) {
    cost = cost.plus((values[v.index] ?? dec('0')).mul(v.pricePerKg).div(100));
  }
  return cost;
}
