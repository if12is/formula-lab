/**
 * I-07: the same formula in different pack sizes: cost per kg (and per L with the measured density) of
 * each size, split into product and non-product (packaging, conversion, quality, one-time), so the effect
 * of pack size is visible. Inputs are full unit costs (`computeUnitCost`), one per size.
 */
import type { SourcedValue } from '../contract';
import { costPerLitre } from '../cost/derived';
import { missing, ok, type Result } from '../core/result';
import { resolveDensity, type ConfidencePolicy } from '../trace/sourced';
import { decOf, inputLeaf, traced, type Traced } from '../trace/trace';
import { duplicateIds } from './line';
import type { UnitCost } from './unit-cost';

export interface SkuCostEntry {
  skuId: string;
  cost: UnitCost;
}

export type CompareBasis = 'kg' | 'L' | 'both';

export interface SkuCompareInput {
  skus: readonly SkuCostEntry[];
  basis: CompareBasis;
  /** Measured product density; required for `L` and `both`. */
  productDensity?: SourcedValue;
  policy: ConfidencePolicy;
}

export interface SkuCompareRow {
  skuId: string;
  fillKg: Traced;
  totalPerUnit: Traced;
  /** EGP per kg of product in the pack. */
  perKg: Traced;
  /** EGP per L; present for `L` and `both`. */
  perL?: Traced;
  productPerKg: Traced;
  /** Everything except the product, per kg: shows the pack-size effect. */
  nonProductPerKg: Traced;
  /** (perKg ÷ cheapest perKg − 1) × 100. */
  vsCheapestPct: Traced;
  /** 1 = cheapest per kg; ties share a rank. */
  rank: number;
}

export const COMPARE_REASONS = {
  EMPTY: 'compare.no_skus',
  DUPLICATE: 'compare.duplicate_sku',
  INVALID_FILL: 'compare.fill_must_be_positive',
} as const;

export function compareSkuSizes(input: SkuCompareInput): Result<SkuCompareRow[]> {
  if (input.skus.length === 0) {
    return missing([{ property: 'skus', reason: COMPARE_REASONS.EMPTY }]);
  }
  const gaps = duplicateIds(input.skus.map((s) => s.skuId), 'skuId', COMPARE_REASONS.DUPLICATE);
  for (const s of input.skus) {
    if (!decOf(s.cost.fillKg).gt(0)) {
      gaps.push({ materialId: s.skuId, property: 'fillKg', reason: COMPARE_REASONS.INVALID_FILL });
    }
  }
  const needsDensity = input.basis !== 'kg';
  const density = needsDensity ? resolveDensity(undefined, 'productDensity', input.productDensity, input.policy, 'Measured product density') : undefined;
  if (density !== undefined && !density.ok) {
    gaps.push(...density.missing);
  }
  if (gaps.length > 0) {
    return missing(gaps, density?.warnings);
  }
  const densityLeaf = density !== undefined && density.ok ? density.value.leaf : undefined;
  const drafts = input.skus.map((s) => {
    const fill = decOf(s.cost.fillKg);
    const total = decOf(s.cost.total);
    const product = decOf(s.cost.sections.product);
    const perKg = traced('div', `${s.skuId} cost per kg = unit cost ÷ fill kg`, total.div(fill), 'EGP', [s.cost.total, s.cost.fillKg]);
    const productPerKg = traced('div', `${s.skuId} product cost per kg`, product.div(fill), 'EGP', [s.cost.sections.product, s.cost.fillKg]);
    const nonProductPerKg = traced('div', `${s.skuId} non-product cost per kg`, total.minus(product).div(fill), 'EGP', [s.cost.total, s.cost.sections.product, s.cost.fillKg]);
    return { s, perKg, productPerKg, nonProductPerKg };
  });
  const cheapest = drafts.reduce((m, d) => (decOf(d.perKg).lt(m) ? decOf(d.perKg) : m), decOf((drafts[0] as (typeof drafts)[number]).perKg));
  const rows = drafts.map((d): SkuCompareRow => {
    const row: SkuCompareRow = {
      skuId: d.s.skuId,
      fillKg: d.s.cost.fillKg,
      totalPerUnit: d.s.cost.total,
      perKg: d.perKg,
      productPerKg: d.productPerKg,
      nonProductPerKg: d.nonProductPerKg,
      vsCheapestPct: traced('div', `${d.s.skuId} vs cheapest % = (perKg ÷ cheapest − 1) × 100`, decOf(d.perKg).div(cheapest).minus(1).mul(100), '%w/w', [d.perKg, inputLeaf('Cheapest cost per kg', cheapest, 'EGP')]),
      rank: 1 + drafts.filter((o) => decOf(o.perKg).lt(decOf(d.perKg))).length,
    };
    if (densityLeaf !== undefined) {
      row.perL = costPerLitre(d.perKg, densityLeaf);
    }
    return row;
  });
  return ok(rows, density?.warnings ?? []);
}

