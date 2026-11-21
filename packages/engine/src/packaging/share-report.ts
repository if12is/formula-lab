/**
 * H-08: packaging cost as a share of the unit cost, per SKU, with the split by item kind.
 * Pure report data (no rounding: the UI rounds for display); shares come from unrounded values.
 */
import { dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { duplicateIds } from '../costing/line';
import type { UnitCost } from '../costing/unit-cost';
import { decOf, traced, type Traced } from '../trace/trace';

export interface PackagingShareKind {
  /** Item kind, or `unspecified` when the BOM item has none. */
  kind: string;
  /** EGP per unit. */
  perUnit: string;
  /** % of the packaging BOM cost. */
  sharePctOfPackaging: string;
  /** % of the full unit cost. */
  sharePctOfUnitCost: string;
}

export interface PackagingShareRow {
  skuId: string;
  packagingPerUnit: Traced;
  totalPerUnit: Traced;
  /** Packaging BOM ÷ full unit cost × 100. */
  packagingSharePct: Traced;
  /** One-time costs (plates, moulds ...) ÷ full unit cost × 100, reported next to it. */
  oneTimeSharePct: Traced;
  byKind: PackagingShareKind[];
}

export const SHARE_REPORT_REASONS = {
  DUPLICATE: 'report.duplicate_sku',
  TOTAL_ZERO: 'report.unit_cost_is_zero',
} as const;

export function packagingShareReport(skus: readonly { skuId: string; cost: UnitCost }[]): Result<PackagingShareRow[]> {
  const gaps = duplicateIds(skus.map((s) => s.skuId), 'skuId', SHARE_REPORT_REASONS.DUPLICATE);
  for (const s of skus) {
    if (decOf(s.cost.total).isZero()) {
      gaps.push({ materialId: s.skuId, property: 'unitCost.total', reason: SHARE_REPORT_REASONS.TOTAL_ZERO });
    }
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const rows = skus.map(({ skuId, cost }): PackagingShareRow => {
    const total = decOf(cost.total);
    const packaging = decOf(cost.sections.packaging);
    const kinds = new Map<string, ReturnType<typeof dec>>();
    for (const l of cost.lines.filter((x) => x.section === 'packaging')) {
      const kind = l.kind ?? 'unspecified';
      kinds.set(kind, (kinds.get(kind) ?? dec('0')).plus(decOf(l.perUnit)));
    }
    return {
      skuId,
      packagingPerUnit: cost.sections.packaging,
      totalPerUnit: cost.total,
      packagingSharePct: traced('div', `${skuId} packaging share % = packaging ÷ unit cost × 100`, packaging.div(total).mul(100), '%w/w', [cost.sections.packaging, cost.total]),
      oneTimeSharePct: traced('div', `${skuId} one-time share % = one-time ÷ unit cost × 100`, decOf(cost.sections.one_time).div(total).mul(100), '%w/w', [cost.sections.one_time, cost.total]),
      byKind: [...kinds].map(([kind, v]) => ({
        kind,
        perUnit: v.toFixed(),
        sharePctOfPackaging: packaging.isZero() ? '0' : v.div(packaging).mul(100).toFixed(),
        sharePctOfUnitCost: v.div(total).mul(100).toFixed(),
      })),
    };
  });
  return ok(rows);
}
