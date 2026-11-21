/**
 * I-01: full unit cost, every line item with its source (plan/00-research/06-cost-drivers.md §1–6).
 *
 *   unit cost = product (fill × cost/kg) + packaging + conversion + quality + regulatory + one-time amortization
 *
 * - Product cost per kg comes from cost/material.ts (landed prices, optionally with `productCostBreakdown`).
 * - `fillActual` is an INPUT: nominal + overfill, sourced (H-06 depends on ES 2295, a human input). No default
 *   overfill. A volume fill is turned into mass with the MEASURED product density (required then).
 * - Every section is required: an absent section is a gap. An explicitly empty list means "none" and is
 *   reported as a warning, never silently.
 * - Every line carries its trace down to the sourced inputs; totals are sums of the UNROUNDED values.
 * - ALL gaps of ALL sections are returned together.
 */
import type { MissingData, SourcedValue } from '../contract';
import { dec } from '../core/decimal';
import { volumeToMass } from '../core/convert';
import type { FxRate } from '../core/fx';
import { dimensionOf, Quantity } from '../core/quantity';
import { missing, ok, type Result } from '../core/result';
import { resolveSourcedNumber } from '../sourced-input';
import { resolveDensity, type ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { packagingCostPerUnit, type PackagingBomInput, type PackagingCost } from '../packaging/bom';
import { amortizeOneTimeCosts, type AmortizedCost, type OneTimeCostInput } from '../packaging/one-time';
import { conversionCostPerUnit, type ConversionCost, type ConversionInput } from './conversion';
import { sumTraced, type CostLine, type LineCurrency } from './line';
import { contributionsTotal, type MaterialContribution } from './product-cost';
import { qualityCostPerUnit, type QualityCost, type QualityInput } from './quality';
import type { MoneyContext } from '../cost/money';

export type CostSection = 'product' | 'packaging' | 'conversion' | 'quality' | 'regulatory' | 'one_time';

export const COST_SECTIONS: readonly CostSection[] = ['product', 'packaging', 'conversion', 'quality', 'regulatory', 'one_time'];

export interface UnitCostLine extends Omit<CostLine, 'currency'> {
  section: CostSection;
  /** `mixed` only for the product line (its materials have their own currencies, see `materialLines`). */
  currency: LineCurrency | 'mixed';
}

export interface UnitCostInput {
  skuId?: string;
  policy: ConfidencePolicy;
  /** Dated rates for every non-EGP price in any section. No default rates. */
  fxRates?: readonly FxRate[];
  product?: {
    /** EGP per net kg (e.g. `productCostBreakdown(...).costPerKg`). */
    costPerKg?: Traced;
    /** Per-material breakdown of `costPerKg`; needed for the USD exposure of the product share. */
    materials?: readonly MaterialContribution[];
  };
  /** Nominal fill + overfill as ONE sourced quantity (mass or volume unit). */
  fillActual?: SourcedValue;
  /** Measured density, required when `fillActual` is a volume. */
  productDensity?: SourcedValue;
  packaging?: Pick<PackagingBomInput, 'items' | 'packing'>;
  conversion?: Omit<ConversionInput, keyof MoneyContext | 'unitMassKg'>;
  quality?: Omit<QualityInput, keyof MoneyContext>;
  oneTime?: readonly OneTimeCostInput[];
}

export interface UnitCost {
  skuId?: string;
  /** Product mass of one selling unit, kg. */
  fillKg: Traced;
  /** Every cost line (one `product` line, then packaging, conversion, quality, regulatory, one-time), EGP per unit. */
  lines: UnitCostLine[];
  /** Per-material split of the product line (informational: NOT added to the total again). Empty without breakdown. */
  materialLines: UnitCostLine[];
  sections: Record<CostSection, Traced>;
  /** EGP per selling unit, from unrounded values. */
  total: Traced;
}

export const UNIT_COST_REASONS = {
  SECTION_MISSING: 'costing.section_missing',
  PRODUCT_COST_MISSING: 'costing.product_cost_per_kg_missing',
  PRODUCT_COST_NOT_EGP: 'costing.product_cost_per_kg_not_egp',
  FILL_MISSING: 'fill.actual_required',
  FILL_INVALID_UNIT: 'fill.actual_must_be_mass_or_volume',
} as const;

function resolveFillKg(input: UnitCostInput): Result<Traced> {
  const sv = input.fillActual;
  const gap = (reason: string): Result<Traced> => missing([{ property: 'fillActual', reason }]);
  if (sv === undefined) {
    return gap(UNIT_COST_REASONS.FILL_MISSING);
  }
  const dimension = dimensionOf(sv.unit);
  if (dimension !== 'mass' && dimension !== 'volume') {
    return gap(UNIT_COST_REASONS.FILL_INVALID_UNIT);
  }
  const fill = resolveSourcedNumber(sv, { property: 'fillActual', label: `Fill actual (${sv.unit})`, unit: sv.unit, min: '0', minExclusive: true }, input.policy);
  if (!fill.ok) {
    return fill;
  }
  const quantity = new Quantity(sv.value, sv.unit);
  if (dimension === 'mass') {
    return ok(traced('convert', 'Fill actual (kg)', quantity.to('kg').value, 'kg', [fill.value.leaf]), fill.warnings);
  }
  const density = resolveDensity(undefined, 'productDensity', input.productDensity, input.policy, 'Measured product density');
  if (!density.ok) {
    return missing(density.missing, fill.warnings);
  }
  const mass = volumeToMass(quantity as Quantity<'L' | 'mL'>, density.value.ref, 'kg');
  /* volumeToMass only fails without a density, which resolveDensity already guarantees */
  const kg = (mass as { ok: true; value: Quantity }).value.value;
  return ok(traced('mul', 'Fill mass (kg) = fill volume × density', kg, 'kg', [fill.value.leaf, density.value.leaf]), [...fill.warnings, ...density.warnings]);
}

function asLines(section: CostSection, lines: readonly CostLine[]): UnitCostLine[] {
  return lines.map((l) => ({ ...l, section }));
}

/** Full cost of one selling unit. Gaps from every section are returned together. */
export function computeUnitCost(input: UnitCostInput): Result<UnitCost> {
  const ctx: MoneyContext = { policy: input.policy, fxRates: input.fxRates };
  const gaps: MissingData[] = [];
  const warnings: string[] = [];
  const sectionGap = (property: string): void => {
    gaps.push({ property, reason: UNIT_COST_REASONS.SECTION_MISSING });
  };
  const take = <T>(r: Result<T>): T | undefined => {
    warnings.push(...r.warnings);
    if (r.ok) {
      return r.value;
    }
    gaps.push(...r.missing);
    return undefined;
  };

  const fillKg = take(resolveFillKg(input));

  // product
  let productLine: UnitCostLine | undefined;
  const materialLines: UnitCostLine[] = [];
  const perKg = input.product?.costPerKg;
  if (input.product === undefined || perKg === undefined) {
    gaps.push({ property: 'product.costPerKg', reason: UNIT_COST_REASONS.PRODUCT_COST_MISSING });
  } else if (perKg.unit !== 'EGP') {
    gaps.push({ property: 'product.costPerKg', reason: UNIT_COST_REASONS.PRODUCT_COST_NOT_EGP });
  } else if (fillKg !== undefined) {
    productLine = {
      key: 'product',
      section: 'product',
      label: 'Product (fill × cost per kg)',
      perUnit: traced('mul', 'Product cost per unit = fill kg × cost per kg', decOf(fillKg).mul(decOf(perKg)), 'EGP', [fillKg, perKg]),
      currency: 'mixed',
    };
    for (const m of input.product.materials ?? []) {
      materialLines.push({
        key: `product:${m.materialId}`,
        section: 'product',
        label: `${m.materialId} (in product)`,
        perUnit: traced('mul', `${m.materialId} per unit = fill kg × per net kg`, decOf(fillKg).mul(decOf(m.perKg)), 'EGP', [fillKg, m.perKg]),
        currency: m.currency,
      });
    }
    if (input.product.materials !== undefined) {
      const drift = contributionsTotal(input.product.materials).minus(decOf(perKg)).abs();
      if (drift.gt(dec('0.000000000001').mul(dec('1').plus(decOf(perKg).abs())))) {
        warnings.push('costing.product_breakdown_mismatch');
      }
    }
  }

  const packaging = input.packaging === undefined ? (sectionGap('packaging'), undefined) : take<PackagingCost>(packagingCostPerUnit({ ...ctx, ...input.packaging }));
  const conversionResult =
    input.conversion === undefined
      ? (sectionGap('conversion'), undefined)
      : conversionCostPerUnit({ ...ctx, ...input.conversion, unitMassKg: fillKg });
  // A per-kg overhead without a fill is a consequence of the fill gap already reported.
  const conversion = conversionResult === undefined ? undefined : take<ConversionCost>(
    !conversionResult.ok && fillKg === undefined
      ? { ...conversionResult, missing: conversionResult.missing.filter((m) => m.property !== 'unitMassKg') }
      : conversionResult,
  );
  const quality = input.quality === undefined ? (sectionGap('quality'), undefined) : take<QualityCost>(qualityCostPerUnit({ ...ctx, ...input.quality }));
  const oneTime = input.oneTime === undefined ? (sectionGap('oneTime'), undefined) : take<AmortizedCost>(amortizeOneTimeCosts(input.oneTime, ctx));

  const lines: UnitCostLine[] = [
    ...(productLine === undefined ? [] : [productLine]),
    ...asLines('packaging', packaging?.lines ?? []),
    ...asLines('conversion', conversion?.lines ?? []),
    ...asLines('quality', (quality?.lines ?? []).filter((l) => !l.key.startsWith('regulatory:'))),
    ...asLines('regulatory', (quality?.lines ?? []).filter((l) => l.key.startsWith('regulatory:'))),
    ...asLines('one_time', oneTime?.lines ?? []),
  ];
  if (gaps.length > 0) {
    return missing(gaps, warnings);
  }
  // No gap means every section resolved (an unresolved one always adds a gap).
  const product = productLine as UnitCostLine;
  const fill = fillKg as Traced;
  const pack = packaging as PackagingCost;
  const conv = conversion as ConversionCost;
  const qual = quality as QualityCost;
  const once = oneTime as AmortizedCost;
  const sections: Record<CostSection, Traced> = {
    product: product.perUnit,
    packaging: pack.total,
    conversion: conv.total,
    quality: qual.tests,
    regulatory: qual.regulatory,
    one_time: once.total,
  };
  for (const [name, list] of [['packaging', pack.lines], ['conversion', conv.lines], ['quality', qual.lines], ['one_time', once.lines]] as const) {
    if (list.length === 0) {
      warnings.push(`costing.section_empty:${name}`);
    }
  }
  const total = sumTraced('Full unit cost (EGP) = product + packaging + conversion + quality + regulatory + one-time', COST_SECTIONS.map((s) => sections[s]));
  const result: UnitCost = { fillKg: fill, lines, materialLines, sections, total };
  if (input.skuId !== undefined) {
    result.skuId = input.skuId;
  }
  return ok(result, warnings);
}
