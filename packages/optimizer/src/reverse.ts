/**
 * J-08: reverse pricing. Pure Decimal, no LP.
 *
 * Walks the price waterfall (plan/00-research/06-cost-drivers.md §8) backwards from the consumer shelf price:
 *
 *   shelf price (incl. VAT)
 *     ÷ (1 + VAT%)                 -> price excluding VAT
 *     remove retailer margin        -> price the retailer pays (wholesaler's selling price)
 *     remove wholesaler margin      -> price at which the wholesaler buys
 *     − distribution cost per unit  -> factory selling price
 *     remove factory margin         -> MAX UNIT COST (everything: formula + packaging + conversion)
 *     ÷ fill (kg per unit)          -> max cost per kg
 *     (− non-formula cost per unit, if given) -> max FORMULA cost per kg, what the LP may spend
 *
 * Every rate and amount is an input; there are NO defaults (a missing one is reported in `missing`, not
 * assumed zero). The margin convention is also an input: `markup_on_cost` (selling = cost × (1 + m)) or
 * `margin_on_price` (selling = cost ÷ (1 − m)); the same convention applies to all three margins.
 * The unit is whatever the shelf price is quoted per (pack, kg or L); `fillKg` is kg of product in it.
 */
import { INPUT_REASONS, isDecimalString, missing, ok, type Dec, type MissingData, type Result } from '@fl/engine';
import { D, str } from './util';

export type MarginBasis = 'markup_on_cost' | 'margin_on_price';

export interface ReversePricingInput {
  /** Consumer price printed on the shelf, including VAT (EGP per selling unit). */
  shelfPriceInclVat?: string;
  /** VAT rate in percent (an explicit "0" is allowed). */
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
}

export interface ReverseStep {
  /** Step key, `optimizer.reverse.step.*`. */
  key: string;
  /** Value after the step, EGP per selling unit (or per kg for the last two). */
  value: string;
}

export interface ReversePricingResult {
  steps: ReverseStep[];
  priceExVat: string;
  retailerPurchasePrice: string;
  wholesalerPurchasePrice: string;
  factoryPrice: string;
  maxUnitCost: string;
  maxCostPerKg: string;
  maxFormulaCostPerKg: string | null;
}

export const REVERSE_REASONS = {
  NON_POSITIVE: 'optimizer.reverse.non_positive_price',
  INVALID_BASIS: 'optimizer.reverse.invalid_margin_basis',
} as const;

type Field = Exclude<keyof ReversePricingInput, 'marginBasis'>;

const RULES: Record<Field, { min: string; minExclusive?: boolean; max?: string; maxExclusive?: boolean }> = {
  shelfPriceInclVat: { min: '0', minExclusive: true },
  vatRatePct: { min: '0', max: '100' },
  retailerMarginPct: { min: '0' },
  wholesalerMarginPct: { min: '0' },
  distributionCostPerUnit: { min: '0' },
  factoryMarginPct: { min: '0' },
  fillKg: { min: '0', minExclusive: true },
  nonFormulaCostPerUnit: { min: '0' },
};

const REQUIRED: readonly Field[] = [
  'shelfPriceInclVat', 'vatRatePct', 'retailerMarginPct', 'wholesalerMarginPct',
  'distributionCostPerUnit', 'factoryMarginPct', 'fillKg',
];

/** Removes a margin from a selling price: the cost that, with the margin applied, gives `selling`. */
function removeMargin(selling: Dec, pct: Dec, basis: MarginBasis): Dec {
  const m = pct.div(100);
  return basis === 'markup_on_cost' ? selling.div(m.plus(1)) : selling.mul(new D(1).minus(m));
}

export function reversePricing(input: ReversePricingInput): Result<ReversePricingResult> {
  const gaps: MissingData[] = [];
  const gap = (property: string, reason: string): void => {
    gaps.push({ property: `reversePricing.${property}`, reason });
  };
  const parsed = new Map<Field, Dec>();
  for (const field of [...REQUIRED, 'nonFormulaCostPerUnit'] as Field[]) {
    const raw = input[field];
    if (raw === undefined) {
      if (field !== 'nonFormulaCostPerUnit') {
        gap(field, INPUT_REASONS.MISSING);
      }
      continue;
    }
    if (!isDecimalString(raw)) {
      gap(field, INPUT_REASONS.INVALID_NUMBER);
      continue;
    }
    const v = new D(raw);
    const rule = RULES[field];
    const lowOk = rule.minExclusive === true ? v.gt(rule.min) : v.gte(rule.min);
    if (!lowOk || (rule.max !== undefined && v.gt(rule.max))) {
      gap(field, INPUT_REASONS.OUT_OF_RANGE);
      continue;
    }
    parsed.set(field, v);
  }
  const basis = input.marginBasis;
  if (basis === undefined) {
    gap('marginBasis', INPUT_REASONS.MISSING);
  } else if (basis !== 'markup_on_cost' && basis !== 'margin_on_price') {
    gap('marginBasis', REVERSE_REASONS.INVALID_BASIS);
  } else if (basis === 'margin_on_price') {
    for (const f of ['retailerMarginPct', 'wholesalerMarginPct', 'factoryMarginPct'] as const) {
      const v = parsed.get(f);
      if (v !== undefined && v.gte(100)) {
        parsed.delete(f);
        gap(f, INPUT_REASONS.OUT_OF_RANGE);
      }
    }
  }
  if (gaps.length > 0 || basis === undefined) {
    return missing(gaps);
  }
  const at = (f: Field): Dec => parsed.get(f) as Dec;

  const steps: ReverseStep[] = [];
  const push = (key: string, v: Dec): Dec => {
    steps.push({ key: `optimizer.reverse.step.${key}`, value: str(v) });
    return v;
  };
  const shelf = push('shelf_price_incl_vat', at('shelfPriceInclVat'));
  const exVat = push('price_ex_vat', shelf.div(at('vatRatePct').div(100).plus(1)));
  const retailerBuys = push('retailer_purchase_price', removeMargin(exVat, at('retailerMarginPct'), basis));
  const wholesalerBuys = push('wholesaler_purchase_price', removeMargin(retailerBuys, at('wholesalerMarginPct'), basis));
  const factoryPrice = push('factory_price', wholesalerBuys.minus(at('distributionCostPerUnit')));
  const failNonPositive = (property: string): Result<ReversePricingResult> =>
    missing([{ property: `reversePricing.${property}`, reason: REVERSE_REASONS.NON_POSITIVE }]);
  if (factoryPrice.lte(0)) {
    return failNonPositive('factoryPrice');
  }
  const maxUnitCost = push('max_unit_cost', removeMargin(factoryPrice, at('factoryMarginPct'), basis));
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
