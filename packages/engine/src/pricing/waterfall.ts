/**
 * Price waterfall, both directions (plan/00-research/06-cost-drivers.md §8). Pure Decimal. This is the ONE
 * implementation: the optimizer's reverse pricing (J-08) calls `backwardWaterfall`.
 *
 *   unit cost
 *     + factory margin               -> factory net price
 *     ÷ (1 − (returns% + promo%)/100) (optional lines) -> factory price
 *     + distribution cost per unit   -> wholesaler purchase price
 *     + wholesaler margin            -> retailer purchase price (wholesale price)
 *     + retailer margin              -> price excluding VAT
 *     × (1 + VAT%)                   -> shelf price
 *
 * Every rate and amount is an input; there are NO defaults (a missing one is reported in `missing`, not
 * assumed zero). The margin basis is an input too: `markup_on_cost` (selling = cost × (1 + m)) or
 * `margin_on_price` (selling = cost ÷ (1 − m)); the same basis applies to all three margins.
 * Returns/promo are optional lines: when absent they are not part of the waterfall.
 */
import type { MissingData } from '../contract';
import { dec, isDecimalString, type Dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { INPUT_REASONS } from '../sourced-input';
import { applyProductTax, productTaxState, resolveProductTax, type ProductTaxState, type TaxQuery, type TaxRegime, type TaxSetting } from './tax';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, inputLeaf, traced, type Traced } from '../trace/trace';

export type MarginBasis = 'markup_on_cost' | 'margin_on_price';

export const WATERFALL_REASONS = {
  NON_POSITIVE: 'pricing.waterfall.non_positive_price',
  INVALID_BASIS: 'pricing.waterfall.invalid_margin_basis',
  UNIT_COST_NOT_EGP: 'pricing.waterfall.unit_cost_not_egp',
} as const;

function str(x: Dec): string {
  return x.isZero() ? '0' : x.toFixed();
}

/** Adds a margin to a cost: the selling price that carries `pct` on the chosen basis. */
export function applyMargin(cost: Dec, pct: Dec, basis: MarginBasis): Dec {
  const m = pct.div(100);
  return basis === 'markup_on_cost' ? cost.mul(m.plus(1)) : cost.div(dec('1').minus(m));
}

/** Removes a margin from a selling price: the cost that, with the margin applied, gives `selling`. */
export function removeMargin(selling: Dec, pct: Dec, basis: MarginBasis): Dec {
  const m = pct.div(100);
  return basis === 'markup_on_cost' ? selling.div(m.plus(1)) : selling.mul(dec('1').minus(m));
}

interface FieldRule {
  min: string;
  minExclusive?: boolean;
  max?: string;
  maxExclusive?: boolean;
}

type Field =
  | 'shelfPriceInclVat'
  | 'vatRatePct'
  | 'retailerMarginPct'
  | 'wholesalerMarginPct'
  | 'distributionCostPerUnit'
  | 'factoryMarginPct'
  | 'fillKg'
  | 'nonFormulaCostPerUnit'
  | 'returnsPct'
  | 'promoPct';

const RULES: Record<Field, FieldRule> = {
  shelfPriceInclVat: { min: '0', minExclusive: true },
  vatRatePct: { min: '0', max: '100' },
  retailerMarginPct: { min: '0' },
  wholesalerMarginPct: { min: '0' },
  distributionCostPerUnit: { min: '0' },
  factoryMarginPct: { min: '0' },
  fillKg: { min: '0', minExclusive: true },
  nonFormulaCostPerUnit: { min: '0' },
  returnsPct: { min: '0', max: '100', maxExclusive: true },
  promoPct: { min: '0', max: '100', maxExclusive: true },
};

const MARGIN_FIELDS = ['retailerMarginPct', 'wholesalerMarginPct', 'factoryMarginPct'] as const;

/** Reads and validates fields in a fixed order; every problem is collected. */
function readFields(
  raw: Partial<Record<Field, string | undefined>>,
  required: readonly Field[],
  optional: readonly Field[],
  gap: (property: string, reason: string) => void,
): Map<Field, Dec> {
  const parsed = new Map<Field, Dec>();
  for (const field of [...required, ...optional]) {
    const value = raw[field];
    if (value === undefined) {
      if (required.includes(field)) {
        gap(field, INPUT_REASONS.MISSING);
      }
      continue;
    }
    if (!isDecimalString(value)) {
      gap(field, INPUT_REASONS.INVALID_NUMBER);
      continue;
    }
    const v = dec(value);
    const rule = RULES[field];
    const lowOk = rule.minExclusive === true ? v.gt(rule.min) : v.gte(rule.min);
    const highOk = rule.max === undefined || (rule.maxExclusive === true ? v.lt(rule.max) : v.lte(rule.max));
    if (!lowOk || !highOk) {
      gap(field, INPUT_REASONS.OUT_OF_RANGE);
      continue;
    }
    parsed.set(field, v);
  }
  return parsed;
}

/** Validates the margin basis; on `margin_on_price` a margin of 100 % or more is unusable. */
function checkBasis(
  basis: MarginBasis | undefined,
  parsed: Map<Field, Dec>,
  gap: (property: string, reason: string) => void,
  invalidBasisReason: string,
): void {
  if (basis === undefined) {
    gap('marginBasis', INPUT_REASONS.MISSING);
  } else if (basis !== 'markup_on_cost' && basis !== 'margin_on_price') {
    gap('marginBasis', invalidBasisReason);
  } else if (basis === 'margin_on_price') {
    for (const f of MARGIN_FIELDS) {
      const v = parsed.get(f);
      if (v !== undefined && v.gte(100)) {
        parsed.delete(f);
        gap(f, INPUT_REASONS.OUT_OF_RANGE);
      }
    }
  }
}

/** Share of the factory price given up to returns and promotion: (returns + promo)/100, or null when neither is given. */
function leakage(parsed: Map<Field, Dec>, gap: (property: string, reason: string) => void): Dec | null {
  const returns = parsed.get('returnsPct');
  const promo = parsed.get('promoPct');
  if (returns === undefined && promo === undefined) {
    return null;
  }
  const total = (returns ?? dec('0')).plus(promo ?? dec('0'));
  if (total.gte(100)) {
    gap('returnsPct', INPUT_REASONS.OUT_OF_RANGE);
    return null;
  }
  return total.div(100);
}

// ---------------------------------------------------------------- backward

export interface BackwardWaterfallInput {
  /** Consumer price printed on the shelf, including VAT (EGP per selling unit). */
  shelfPriceInclVat?: string;
  /** VAT rate in percent (an explicit "0" is allowed). Take it from `resolveTaxRegime`. */
  vatRatePct?: string;
  retailerMarginPct?: string;
  wholesalerMarginPct?: string;
  /** Transport + distribution, EGP per selling unit. */
  distributionCostPerUnit?: string;
  factoryMarginPct?: string;
  marginBasis?: MarginBasis;
  /** Kg of product per selling unit (pack fill; 1 for per-kg; density for per-L). */
  fillKg?: string;
  /** Packaging + conversion etc. per selling unit. Optional: without it `maxFormulaCostPerKg` is null. */
  nonFormulaCostPerUnit?: string;
  /** Optional line: returns / market waste, percent of the factory price. */
  returnsPct?: string;
  /** Optional line: promotion, percent of the factory price. */
  promoPct?: string;
}

export interface WaterfallStep {
  /** Step key (prefixed per caller). */
  key: string;
  /** Value after the step, EGP per selling unit (per kg for the last two backward steps). */
  value: string;
}

export interface BackwardWaterfallResult {
  steps: WaterfallStep[];
  priceExVat: string;
  retailerPurchasePrice: string;
  wholesalerPurchasePrice: string;
  factoryPrice: string;
  maxUnitCost: string;
  maxCostPerKg: string;
  maxFormulaCostPerKg: string | null;
}

/** Caller-specific message keys, so wrappers (the optimizer) keep their own namespaces. */
export interface WaterfallLabels {
  /** Prefix of every reported `property`, default `waterfall.`. */
  propertyPrefix?: string;
  /** Prefix of every step key, default `pricing.waterfall.step.`. */
  stepKeyPrefix?: string;
  nonPositiveReason?: string;
  invalidBasisReason?: string;
}

/** Shelf price => maximum unit cost / cost per kg / formula cost per kg. */
export function backwardWaterfall(input: BackwardWaterfallInput, labels: WaterfallLabels = {}): Result<BackwardWaterfallResult> {
  const propertyPrefix = labels.propertyPrefix ?? 'waterfall.';
  const stepKeyPrefix = labels.stepKeyPrefix ?? 'pricing.waterfall.step.';
  const gaps: MissingData[] = [];
  const gap = (property: string, reason: string): void => {
    gaps.push({ property: `${propertyPrefix}${property}`, reason });
  };
  const parsed = readFields(
    input,
    ['shelfPriceInclVat', 'vatRatePct', 'retailerMarginPct', 'wholesalerMarginPct', 'distributionCostPerUnit', 'factoryMarginPct', 'fillKg'],
    ['nonFormulaCostPerUnit', 'returnsPct', 'promoPct'],
    gap,
  );
  checkBasis(input.marginBasis, parsed, gap, labels.invalidBasisReason ?? WATERFALL_REASONS.INVALID_BASIS);
  const leak = leakage(parsed, gap);
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const basis = input.marginBasis as MarginBasis;
  const at = (f: Field): Dec => parsed.get(f) as Dec;

  const steps: WaterfallStep[] = [];
  const push = (key: string, v: Dec): Dec => {
    steps.push({ key: `${stepKeyPrefix}${key}`, value: str(v) });
    return v;
  };
  const shelf = push('shelf_price_incl_vat', at('shelfPriceInclVat'));
  const exVat = push('price_ex_vat', shelf.div(at('vatRatePct').div(100).plus(1)));
  const retailerBuys = push('retailer_purchase_price', removeMargin(exVat, at('retailerMarginPct'), basis));
  const wholesalerBuys = push('wholesaler_purchase_price', removeMargin(retailerBuys, at('wholesalerMarginPct'), basis));
  const factoryPrice = push('factory_price', wholesalerBuys.minus(at('distributionCostPerUnit')));
  if (factoryPrice.lte(0)) {
    return missing([{ property: `${propertyPrefix}factoryPrice`, reason: labels.nonPositiveReason ?? WATERFALL_REASONS.NON_POSITIVE }]);
  }
  const factoryNet = leak === null ? factoryPrice : push('factory_net_price', factoryPrice.mul(dec('1').minus(leak)));
  const maxUnitCost = push('max_unit_cost', removeMargin(factoryNet, at('factoryMarginPct'), basis));
  const maxPerKg = push('max_cost_per_kg', maxUnitCost.div(at('fillKg')));
  const nonFormula = parsed.get('nonFormulaCostPerUnit');
  const maxFormulaPerKg = nonFormula === undefined ? null : push('max_formula_cost_per_kg', maxUnitCost.minus(nonFormula).div(at('fillKg')));

  return ok({
    steps,
    priceExVat: str(exVat),
    retailerPurchasePrice: str(retailerBuys),
    wholesalerPurchasePrice: str(wholesalerBuys),
    factoryPrice: str(factoryPrice),
    maxUnitCost: str(maxUnitCost),
    maxCostPerKg: str(maxPerKg),
    maxFormulaCostPerKg: maxFormulaPerKg === null ? null : str(maxFormulaPerKg),
  });
}

// ----------------------------------------------------------------- forward

export interface ForwardWaterfallInput {
  /** Full unit cost, EGP per selling unit (e.g. `UnitCost.total`). */
  unitCost?: Traced;
  marginBasis?: MarginBasis;
  factoryMarginPct?: string;
  distributionCostPerUnit?: string;
  wholesalerMarginPct?: string;
  retailerMarginPct?: string;
  returnsPct?: string;
  promoPct?: string;
  /**
   * The product's tax setting (disabled or a rate), the dated regimes the rate is checked against, the family/type
   * and the date the price is valid on. No setting is `missing`, never 0 %.
   */
  tax?: { setting?: TaxSetting; regimes: readonly TaxRegime[] } & TaxQuery;
  policy: ConfidencePolicy;
}

export interface ForwardWaterfallResult {
  /** Traced steps in order: unit_cost, [factory_net_price], factory_price, wholesaler_purchase_price, retailer_purchase_price, price_ex_vat, then (tax applied only) shelf_price_incl_vat. */
  steps: { key: string; value: Traced }[];
  factoryPrice: Traced;
  wholesalerPurchasePrice: Traced;
  /** Wholesale price: what the retailer pays. */
  retailerPurchasePrice: Traced;
  priceExVat: Traced;
  /** What the price result says about tax: disabled (no tax line) or applied at a rate (a 0 % rate is still a line). */
  tax: ProductTaxState;
  /** The tax line; null when tax is disabled. */
  vatAmount: Traced | null;
  /** Price including tax; null when tax is disabled (the price to the consumer is then `consumerPrice`). */
  shelfPriceInclVat: Traced | null;
  /** The price the consumer pays: with tax when applied, the price without tax when disabled. */
  consumerPrice: Traced;
}

/** Cost => factory => wholesale => retail => + VAT. Missing/invalid inputs are all listed. */
export function forwardWaterfall(input: ForwardWaterfallInput): Result<ForwardWaterfallResult> {
  const gaps: MissingData[] = [];
  const gap = (property: string, reason: string): void => {
    gaps.push({ property: `waterfall.${property}`, reason });
  };
  const parsed = readFields(
    input,
    ['retailerMarginPct', 'wholesalerMarginPct', 'distributionCostPerUnit', 'factoryMarginPct'],
    ['returnsPct', 'promoPct'],
    gap,
  );
  if (input.unitCost === undefined) {
    gap('unitCost', INPUT_REASONS.MISSING);
  } else if (input.unitCost.unit !== 'EGP' || decOf(input.unitCost).isNegative()) {
    gap('unitCost', WATERFALL_REASONS.UNIT_COST_NOT_EGP);
  }
  checkBasis(input.marginBasis, parsed, gap, WATERFALL_REASONS.INVALID_BASIS);
  const leak = leakage(parsed, gap);
  const vat =
    input.tax === undefined
      ? missing<never>([{ property: 'waterfall.tax', reason: INPUT_REASONS.MISSING }])
      : resolveProductTax(input.tax.setting, input.tax.regimes, input.tax, input.policy);
  if (!vat.ok || gaps.length > 0) {
    return missing([...(vat.ok ? [] : vat.missing), ...gaps], vat.warnings);
  }
  const basis = input.marginBasis as MarginBasis;
  const at = (f: Field): Dec => parsed.get(f) as Dec;
  const pctLeaf = (label: string, f: Field): Traced => inputLeaf(label, at(f), '%w/w');
  const steps: { key: string; value: Traced }[] = [];
  const push = (key: string, value: Traced): Traced => {
    steps.push({ key, value });
    return value;
  };

  const cost = push('unit_cost', input.unitCost as Traced);
  const net = traced('margin', `Factory price = cost + factory margin (${basis})`, applyMargin(decOf(cost), at('factoryMarginPct'), basis), 'EGP', [cost, pctLeaf('Factory margin %', 'factoryMarginPct')]);
  let factory = net;
  if (leak !== null) {
    push('factory_net_price', net);
    const keep = inputLeaf('Kept share = 1 − (returns% + promo%)/100', dec('1').minus(leak), '%w/w');
    factory = traced('div', 'Factory price = net ÷ kept share', decOf(net).div(keep.value), 'EGP', [net, keep]);
  }
  push('factory_price', factory);
  const distribution = inputLeaf('Distribution cost per unit', at('distributionCostPerUnit'), 'EGP');
  const wholesalerBuys = push('wholesaler_purchase_price', traced('add', 'Wholesaler purchase price = factory price + distribution', decOf(factory).plus(distribution.value), 'EGP', [factory, distribution]));
  const retailerBuys = push('retailer_purchase_price', traced('margin', `Wholesale price = wholesaler price + wholesaler margin (${basis})`, applyMargin(decOf(wholesalerBuys), at('wholesalerMarginPct'), basis), 'EGP', [wholesalerBuys, pctLeaf('Wholesaler margin %', 'wholesalerMarginPct')]));
  const exVat = push('price_ex_vat', traced('margin', `Price excl. VAT = wholesale price + retailer margin (${basis})`, applyMargin(decOf(retailerBuys), at('retailerMarginPct'), basis), 'EGP', [retailerBuys, pctLeaf('Retailer margin %', 'retailerMarginPct')]));
  const { taxAmount: vatAmount, priceWithTax } = applyProductTax(exVat, vat.value);
  if (vatAmount !== null) {
    push('shelf_price_incl_vat', priceWithTax);
  }

  return ok(
    {
      steps,
      factoryPrice: factory,
      wholesalerPurchasePrice: wholesalerBuys,
      retailerPurchasePrice: retailerBuys,
      priceExVat: exVat,
      tax: productTaxState(vat.value),
      vatAmount,
      shelfPriceInclVat: vatAmount === null ? null : priceWithTax,
      consumerPrice: priceWithTax,
    },
    vat.warnings,
  );
}
