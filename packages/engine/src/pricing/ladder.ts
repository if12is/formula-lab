/**
 * S-13: the price ladder of a product, in both directions, plus its sensitivity to the dollar. No new price model: it hands the
 * screen's inputs to the ONE waterfall (`forwardWaterfall` / `backwardWaterfall`, pricing/waterfall.ts) and the product's tax to
 * `resolveProductTax`, and only adds (a) the unit cost as a sourced EGP leaf, (b) the tax resolved once for both directions and
 * (c) the USD scenario: the dollar moves by x %, the USD-priced part of the material cost moves by x % with it, the unit cost moves
 * by that amount and the ladder is walked again.
 *
 * Every rate and amount is an input (no defaults; a missing one is `missing`, never 0). Tax disabled means the consumer price has
 * no tax line, so the reverse direction takes the shelf price as the price excluding tax (rate 0, stated by the setting, not assumed).
 * EGP prices that follow the dollar in practice are not detectable here and count as EGP (same rule as costing/exposure.ts).
 */
import type { MissingData, SourcedValue } from '../contract';
import { dec, isDecimalString, type Dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { sourcedEgp } from '../cost/money';
import type { CostReport } from '../costing/cost-report';
import type { Candidate } from '../data/resolve';
import { INPUT_REASONS } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf } from '../trace/trace';
import { resolveProductTax, type ProductTax, type TaxRegime, type TaxSetting } from './tax';
import {
  backwardWaterfall,
  forwardWaterfall,
  type BackwardWaterfallResult,
  type ForwardWaterfallResult,
  type MarginBasis,
} from './waterfall';

export const LADDER_REASONS = {
  FILL_REQUIRED: 'pricing.ladder.fill_required',
  USD_SHARE_UNAVAILABLE: 'pricing.ladder.usd_exposure_unavailable',
  INVALID_CHANGE: 'pricing.ladder.invalid_change_pct',
} as const;

/** Margins and costs between the factory and the shelf; the same fields as the waterfall. */
export interface LadderRates {
  marginBasis?: MarginBasis;
  factoryMarginPct?: string;
  distributionCostPerUnit?: string;
  wholesalerMarginPct?: string;
  retailerMarginPct?: string;
  returnsPct?: string;
  promoPct?: string;
}

export interface LadderTax {
  setting?: TaxSetting;
  regimes: readonly TaxRegime[];
  productFamilyId: string;
  taxType: string;
  /** ISO date the price is valid on (an input; the engine never reads a clock). */
  asOf: string;
}

export interface LadderForwardInput extends LadderRates {
  /** The product's unit cost, EGP per selling unit, with its source. */
  unitCost?: SourcedValue;
  tax?: LadderTax;
  policy?: ConfidencePolicy;
}

export interface LadderReverseInput extends LadderRates {
  /** Consumer price per selling unit, including tax when the product has one. */
  shelfPriceInclVat?: string;
  /** Kg of product per selling unit. */
  fillKg?: string;
  /** Packaging + conversion etc. per selling unit; without it the maximum FORMULA cost is not given. */
  nonFormulaCostPerUnit?: string;
  tax?: LadderTax;
  policy?: ConfidencePolicy;
}

/** Cost -> shelf. */
export function ladderForward(input: LadderForwardInput): Result<ForwardWaterfallResult> {
  const policy = input.policy ?? {};
  const unitCost = input.unitCost === undefined ? undefined : sourcedEgp(undefined, 'unitCost', 'Unit cost (EGP per unit)', input.unitCost, { policy });
  if (unitCost !== undefined && !unitCost.ok) {
    // Report this gap together with the ones the waterfall finds in the other inputs.
    const rest = forwardWaterfall({ ...input, unitCost: undefined, policy });
    return missing([...unitCost.missing, ...(rest.ok ? [] : rest.missing.filter((m) => m.property !== 'waterfall.unitCost'))], unitCost.warnings);
  }
  return forwardWaterfall({
    unitCost: unitCost?.ok === true ? unitCost.value : undefined,
    marginBasis: input.marginBasis,
    factoryMarginPct: input.factoryMarginPct,
    distributionCostPerUnit: input.distributionCostPerUnit,
    wholesalerMarginPct: input.wholesalerMarginPct,
    retailerMarginPct: input.retailerMarginPct,
    returnsPct: input.returnsPct,
    promoPct: input.promoPct,
    tax: input.tax === undefined ? undefined : { setting: input.tax.setting, regimes: input.tax.regimes, productFamilyId: input.tax.productFamilyId, taxType: input.tax.taxType, asOf: input.tax.asOf },
    policy,
  });
}

/** The rate the backward waterfall divides by: the product's regime rate, or 0 when the product has tax switched off. */
function vatRateOf(tax: LadderTax | undefined, policy: ConfidencePolicy): Result<{ ratePct: string; state: ProductTax['state'] }> {
  if (tax === undefined) {
    return missing([{ property: 'waterfall.tax', reason: INPUT_REASONS.MISSING }]);
  }
  const resolved = resolveProductTax(tax.setting, tax.regimes, tax, policy);
  if (!resolved.ok) {
    return resolved;
  }
  return ok({ state: resolved.value.state, ratePct: resolved.value.state === 'disabled' ? '0' : resolved.value.regime.ratePct }, resolved.warnings);
}

/** Shelf -> maximum unit cost / cost per kg / formula cost per kg. */
export function ladderReverse(input: LadderReverseInput): Result<BackwardWaterfallResult & { taxState: ProductTax['state']; vatRatePct: string }> {
  const policy = input.policy ?? {};
  const vat = vatRateOf(input.tax, policy);
  const walked = backwardWaterfall({
    shelfPriceInclVat: input.shelfPriceInclVat,
    vatRatePct: vat.ok ? vat.value.ratePct : undefined,
    retailerMarginPct: input.retailerMarginPct,
    wholesalerMarginPct: input.wholesalerMarginPct,
    distributionCostPerUnit: input.distributionCostPerUnit,
    factoryMarginPct: input.factoryMarginPct,
    marginBasis: input.marginBasis,
    fillKg: input.fillKg,
    nonFormulaCostPerUnit: input.nonFormulaCostPerUnit,
    returnsPct: input.returnsPct,
    promoPct: input.promoPct,
  });
  if (!vat.ok) {
    // The tax gap comes first; the waterfall's own "vatRatePct" gap is the same thing, so it is not listed twice.
    return missing([...vat.missing, ...(walked.ok ? [] : walked.missing.filter((m) => m.property !== 'waterfall.vatRatePct'))], vat.warnings);
  }
  return walked.ok ? ok({ ...walked.value, taxState: vat.value.state, vatRatePct: vat.value.ratePct }, [...vat.warnings, ...walked.warnings]) : missing(walked.missing, vat.warnings);
}

// ------------------------------------------------------------------ USD

export interface UsdLinkedCost {
  /** Σ contributions priced in USD, EGP per kg of product. */
  usdLinkedPerKg: string;
  /** Material cost per kg (the report total). */
  materialCostPerKg: string;
  /** usdLinked / total × 100 */
  usdSharePct: string;
}

/**
 * The part of the material cost per kg that moves with the dollar, read off the cost report (the same figures the cost report
 * screen shows): the lines whose chosen quote is in USD. A report with gaps has no total, so no share: `missing`, never a guess.
 */
export function usdLinkedCost(report: CostReport, quotes: Readonly<Record<string, readonly Candidate[]>>): Result<UsdLinkedCost> {
  if (!report.ok || report.total === null) {
    return missing(report.missing.length > 0 ? report.missing : [{ property: 'costReport', reason: LADDER_REASONS.USD_SHARE_UNAVAILABLE }]);
  }
  let linked = dec('0');
  for (const line of report.lines) {
    const quote = line.quoteIndex === null ? undefined : quotes[line.materialId]?.[line.quoteIndex];
    if (quote?.unit === 'USD' && line.contributionEgpPerKg !== null) {
      linked = linked.plus(line.contributionEgpPerKg);
    }
  }
  const total = dec(report.total.value);
  if (total.isZero()) {
    return missing([{ property: 'costReport.total', reason: LADDER_REASONS.USD_SHARE_UNAVAILABLE }]);
  }
  return ok({ usdLinkedPerKg: linked.toFixed(), materialCostPerKg: total.toFixed(), usdSharePct: linked.div(total).mul(100).toFixed() });
}

export interface FxScenarioRow {
  /** The dollar's move, percent. */
  changePct: string;
  /** The dated USD->EGP rate after the move (rate x (1 + change/100)); null when no rate was given. */
  usdRateAfter: string | null;
  /** Unit cost after the move, EGP per unit. */
  unitCost: string;
  /** New unit cost minus the base one. */
  deltaUnitCost: string;
  /** Consumer price after the move; null while the ladder cannot be walked (a gap in `missing`). */
  consumerPrice: string | null;
  /** New consumer price minus the base one. */
  deltaConsumerPrice: string | null;
  /** Δ consumer price ÷ base consumer price × 100. */
  deltaConsumerPct: string | null;
  missing: MissingData[];
}

export interface FxSensitivity {
  /** USD-linked part of the unit cost: usdLinkedPerKg × fillKg, EGP per unit. */
  usdLinkedPerUnit: string;
  baseUnitCost: string;
  baseConsumerPrice: string;
  rows: FxScenarioRow[];
}

export interface FxSensitivityInput {
  forward: LadderForwardInput;
  /** USD-linked cost per kg of product (usdLinkedCost). */
  usdLinkedPerKg?: string;
  /** Kg of product per selling unit. */
  fillKg?: string;
  /** The dollar's moves to try, percent (negative allowed, > −100). */
  changesPct: readonly string[];
  /** The USD->EGP rate in force now (from the app's dated rate lookup), to show what each move means in pounds per dollar. */
  usdRate?: string;
}

/** What a move of the dollar does to the unit cost and to the consumer price, by walking the SAME ladder again. */
export function fxSensitivity(input: FxSensitivityInput): Result<FxSensitivity> {
  const gaps: MissingData[] = [];
  if (input.fillKg === undefined || !isDecimalString(input.fillKg) || dec(input.fillKg).lte(0)) {
    gaps.push({ property: 'fx.fillKg', reason: input.fillKg === undefined ? INPUT_REASONS.MISSING : INPUT_REASONS.OUT_OF_RANGE });
  }
  if (input.usdLinkedPerKg === undefined || !isDecimalString(input.usdLinkedPerKg)) {
    gaps.push({ property: 'fx.usdLinkedPerKg', reason: LADDER_REASONS.USD_SHARE_UNAVAILABLE });
  }
  const base = ladderForward(input.forward);
  if (!base.ok) {
    gaps.push(...base.missing);
  }
  if (gaps.length > 0 || !base.ok || input.forward.unitCost === undefined) {
    return missing(gaps.length > 0 ? gaps : [{ property: 'waterfall.unitCost', reason: INPUT_REASONS.MISSING }], base.warnings);
  }
  const baseUnitCost = input.forward.unitCost;
  const usdLinkedPerUnit = dec(input.usdLinkedPerKg as string).mul(input.fillKg as string);
  const baseCost = dec(baseUnitCost.value);
  const basePrice = decOf(base.value.consumerPrice);
  const rows = input.changesPct.map((changePct): FxScenarioRow => {
    if (!isDecimalString(changePct) || dec(changePct).lte(-100)) {
      return { changePct, usdRateAfter: null, unitCost: '', deltaUnitCost: '', consumerPrice: null, deltaConsumerPrice: null, deltaConsumerPct: null, missing: [{ property: 'fx.changePct', reason: LADDER_REASONS.INVALID_CHANGE }] };
    }
    const delta: Dec = usdLinkedPerUnit.mul(changePct).div(100);
    const usdRateAfter = input.usdRate !== undefined && isDecimalString(input.usdRate) ? dec(input.usdRate).mul(dec(changePct).div(100).plus(1)).toFixed() : null;
    const unitCost = baseCost.plus(delta);
    const walked = ladderForward({ ...input.forward, unitCost: { ...baseUnitCost, value: unitCost.toFixed() } });
    if (!walked.ok) {
      return { changePct, usdRateAfter, unitCost: unitCost.toFixed(), deltaUnitCost: delta.toFixed(), consumerPrice: null, deltaConsumerPrice: null, deltaConsumerPct: null, missing: walked.missing };
    }
    const price = decOf(walked.value.consumerPrice);
    return {
      changePct,
      usdRateAfter,
      unitCost: unitCost.toFixed(),
      deltaUnitCost: delta.toFixed(),
      consumerPrice: price.toFixed(),
      deltaConsumerPrice: price.minus(basePrice).toFixed(),
      deltaConsumerPct: basePrice.isZero() ? null : price.minus(basePrice).div(basePrice).mul(100).toFixed(),
      missing: [],
    };
  });
  return ok({ usdLinkedPerUnit: usdLinkedPerUnit.toFixed(), baseUnitCost: baseCost.toFixed(), baseConsumerPrice: basePrice.toFixed(), rows }, base.warnings);
}
