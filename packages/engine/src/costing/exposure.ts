/**
 * I-06: USD exposure of the unit cost (plan/00-research/06-cost-drivers.md §9).
 *
 * A line is USD-linked when the price it is built on is quoted in USD (it went through a dated USD->EGP rate).
 * Every line is linear in its price, so:
 *   - "USD +x %"        new total = total + USD-linked × x/100 (the rate moves, the USD prices do not)
 *   - "item price +x %" new total = total + line × x/100
 * Both are exact and deterministic. For the product share the per-material breakdown is required
 * (`UnitCostInput.product.materials`); without it the exposure is `missing`, never guessed.
 * EGP prices that follow the dollar in practice are not detectable here and count as EGP.
 */
import { dec, isDecimalString, type Dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { decOf, inputLeaf, traced, type Traced } from '../trace/trace';
import { sumTraced } from './line';
import type { UnitCost, UnitCostLine } from './unit-cost';

export interface UsdLineExposure {
  key: string;
  /** EGP per unit. */
  perUnit: Traced;
  /** EGP per unit that moves with the dollar. */
  usdLinked: Traced;
  /** usdLinked / perUnit × 100; null for a zero line. */
  usdSharePct: string | null;
}

export interface UsdExposure {
  total: Traced;
  usdLinked: Traced;
  /** usdLinked / total × 100. */
  usdSharePct: Traced;
  /** Leaf lines: every non-product line, plus each material of the product when a breakdown was given. */
  byLine: UsdLineExposure[];
}

export const EXPOSURE_REASONS = {
  BREAKDOWN_REQUIRED: 'exposure.product_breakdown_required',
  TOTAL_ZERO: 'exposure.total_cost_is_zero',
  INVALID_PCT: 'exposure.invalid_change_pct',
  LINE_NOT_FOUND: 'exposure.line_not_found',
} as const;

function exposureOf(line: UnitCostLine): Traced {
  const v = line.currency === 'USD' ? decOf(line.perUnit) : dec('0');
  return traced('usd_linked', `${line.key} USD-linked share (EGP)`, v, 'EGP', [line.perUnit]);
}

/** Leaf lines for exposure: product is replaced by its materials. */
function leafLines(cost: UnitCost): UnitCostLine[] {
  return cost.lines.flatMap((l) => (l.key === 'product' ? cost.materialLines : [l]));
}

/** Share of the unit cost linked to USD, in total and per line. */
export function usdExposure(cost: UnitCost): Result<UsdExposure> {
  if (cost.materialLines.length === 0 && cost.lines.some((l) => l.key === 'product')) {
    return missing([{ property: 'product.materials', reason: EXPOSURE_REASONS.BREAKDOWN_REQUIRED }]);
  }
  const total = decOf(cost.total);
  if (total.isZero()) {
    return missing([{ property: 'unitCost.total', reason: EXPOSURE_REASONS.TOTAL_ZERO }]);
  }
  const byLine = leafLines(cost).map((l): UsdLineExposure => {
    const usdLinked = exposureOf(l);
    const line = decOf(l.perUnit);
    return { key: l.key, perUnit: l.perUnit, usdLinked, usdSharePct: line.isZero() ? null : decOf(usdLinked).div(line).mul(100).toFixed() };
  });
  const usdLinked = sumTraced('USD-linked unit cost (EGP) = Σ USD-linked lines', byLine.map((b) => b.usdLinked));
  const usdSharePct = traced('div', 'USD-linked share of unit cost % = USD-linked ÷ total × 100', decOf(usdLinked).div(total).mul(100), '%w/w', [usdLinked, cost.total]);
  return ok({ total: cost.total, usdLinked, usdSharePct, byLine });
}

export interface PriceScenario {
  baseTotal: Traced;
  newTotal: Traced;
  /** newTotal − baseTotal, EGP per unit. */
  delta: Traced;
  /** delta / baseTotal × 100. */
  deltaPct: Traced;
}

function parseChange(pct: string): Dec | null {
  return isDecimalString(pct) && dec(pct).gte(-100) ? dec(pct) : null;
}

function scenario(cost: UnitCost, deltaEgp: Dec, label: string, inputs: readonly Traced[]): PriceScenario {
  const base = decOf(cost.total);
  const delta = traced('scenario', `${label}: Δ unit cost (EGP)`, deltaEgp, 'EGP', inputs);
  const newTotal = traced('add', `${label}: new unit cost (EGP)`, base.plus(deltaEgp), 'EGP', [cost.total, delta]);
  const deltaPct = traced('div', `${label}: Δ % = Δ ÷ base × 100`, base.isZero() ? dec('0') : deltaEgp.div(base).mul(100), '%w/w', [delta, cost.total]);
  return { baseTotal: cost.total, newTotal, delta, deltaPct };
}

/** Scenario: the USD rate moves by `usdChangePct` % (negative allowed, > −100 inclusive). */
export function usdScenario(cost: UnitCost, usdChangePct: string): Result<PriceScenario> {
  const change = parseChange(usdChangePct);
  if (change === null) {
    return missing([{ property: 'usdChangePct', reason: EXPOSURE_REASONS.INVALID_PCT }]);
  }
  const exposure = usdExposure(cost);
  if (!exposure.ok) {
    return exposure;
  }
  const leaf = inputLeaf('USD change %', change, '%w/w');
  return ok(scenario(cost, decOf(exposure.value.usdLinked).mul(change).div(100), `USD ${usdChangePct}%`, [exposure.value.usdLinked, leaf]));
}

/** Scenario: the price behind ONE line (`packaging:<id>`, `conversion:<id>`, `product:<materialId>` ...) moves by `changePct` %. */
export function itemPriceScenario(cost: UnitCost, lineKey: string, changePct: string): Result<PriceScenario> {
  const change = parseChange(changePct);
  if (change === null) {
    return missing([{ property: 'changePct', reason: EXPOSURE_REASONS.INVALID_PCT }]);
  }
  const line = [...cost.lines, ...cost.materialLines].find((l) => l.key === lineKey);
  if (line === undefined) {
    return missing([{ property: lineKey, reason: EXPOSURE_REASONS.LINE_NOT_FOUND }]);
  }
  const leaf = inputLeaf('Price change %', change, '%w/w');
  return ok(scenario(cost, decOf(line.perUnit).mul(change).div(100), `${lineKey} ${changePct}%`, [line.perUnit, leaf]));
}
