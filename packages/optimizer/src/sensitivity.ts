/**
 * J-07: sensitivity helpers.
 *
 * Two views, both deterministic:
 *  - Duals from HiGHS (stage-1 cost solve): `rowDual` = ∂cost/∂rhs of each constraint as written in the
 *    LP (cost in EGP per kg of formula, rhs in the row's unit: percent points, or EGP/kg for cost rows),
 *    `reducedCost` = how much a material's price (per percent) would have to fall before it enters the
 *    formula. They are local (valid for small changes) and, for a degenerate LP, one of several valid duals
 *    (still the same one every run).
 *  - Explicit scenarios: "price of material X changes by p %": a full re-solve with the same machinery,
 *    reporting the new formula, the new cost, and the delta (optimize.ts runs them).
 */
import { D, dec, fromDouble, str, type Dec } from './util';

/** A "price of X changes by changePct %" scenario. `changePct` is a decimal string, e.g. "10" or "-5". */
export interface PriceScenario {
  materialId: string;
  changePct: string;
}

export interface FormulaDelta {
  materialId: string;
  basePercentWW: string;
  scenarioPercentWW: string;
  deltaPercentWW: string;
}

export interface ScenarioResult {
  materialId: string;
  changePct: string;
  basePriceEGPperKg: string;
  scenarioPriceEGPperKg: string;
  status: 'optimal' | 'infeasible' | 'missing' | 'error';
  /** Present when status is optimal. */
  scenarioCostPerKg: string | null;
  costDeltaPerKg: string | null;
  /** costDelta / base cost × 100, rounded to 0.001. null when the base cost is 0. */
  costDeltaPct: string | null;
  /** What the OLD formula would cost at the new price (no re-optimisation): the exposure if you do nothing. */
  costDeltaIfFormulaUnchangedPerKg: string | null;
  formulaChanged: boolean;
  formulaDelta: FormulaDelta[];
}

/** Exact price after a percent change: P × (1 + p/100). */
export function changedPrice(price: Dec, changePct: string): Dec {
  return price.mul(dec(changePct).div(100).plus(1));
}

/** Solver double -> 9 significant digit decimal string; |x| < 1e-9 is reported as exactly "0". */
export function dualString(x: number): string {
  const d = fromDouble(x);
  return d.abs().lt('0.000000001') ? '0' : str(d.toSignificantDigits(9));
}

export function percentDelta(delta: Dec, base: Dec): string | null {
  return base.isZero() ? null : str(delta.div(base).mul(100).toDecimalPlaces(3, D.ROUND_HALF_EVEN));
}
