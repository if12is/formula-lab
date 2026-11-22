/**
 * One line of a customer price list: the price a merchant pays for one product (EGP per selling unit, BEFORE tax: Sanad
 * adds the product's tax on top of a list price) checked against the product's unit cost.
 *
 * The price is never invented. It is one of two things, both inputs with a source and a confidence level:
 *   - `rule`:     a margin rule (basis + margin %, optionally a distribution cost per unit) applied to the unit cost with the
 *                 SAME margin primitive the price waterfall uses (`applyMargin`): price = cost + margin [+ distribution];
 *   - `override`: an explicit price somebody sourced (an agreement with the merchant, a quotation).
 * Without a unit cost, or without a complete rule / override, the line is `missing` with every gap listed, never 0.
 *
 * The published price is the exact price rounded to 0.01 (half-even, the money step of the rounding policy). Margin figures
 * are taken from that published price, so what the person reads is what Sanad receives. A price below cost is a result with
 * `belowCost: true` (a warning for the person), not a refusal: selling at a loss is a business decision.
 */
import type { MissingData, SourcedValue } from '../contract';
import type { Dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { roundPercent, roundToStep, DISPLAY_STEP } from '../core/round';
import { INPUT_REASONS, resolveSourcedNumber } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { applyMargin, type MarginBasis } from './waterfall';

export const PRICE_LIST_REASONS = {
  /** Neither a rule nor an override was chosen for the line. */
  SPEC_MISSING: 'price_list.spec_missing',
  SPEC_INVALID: 'price_list.spec_invalid',
  INVALID_BASIS: 'price_list.invalid_margin_basis',
} as const;

export interface PriceRuleInput {
  /** The rule's key in the configuration (shown in the trace). */
  key: string;
  basis?: MarginBasis;
  /** Margin in percent (unit `%w/w`), with its source and confidence. */
  marginPct?: SourcedValue;
  /** Optional line: distribution cost per unit (unit `EGP`), added after the margin. */
  distributionCostPerUnit?: SourcedValue;
}

export type PriceLineSpec =
  | { mode: 'rule'; rule?: PriceRuleInput }
  | { mode: 'override'; /** The explicit price, EGP per unit before tax, sourced. */ price?: SourcedValue };

export interface PriceListLineInput {
  /** The product's unit cost, EGP per selling unit, with its source. */
  unitCost?: SourcedValue;
  spec?: PriceLineSpec;
  policy: ConfidencePolicy;
}

export interface PriceListLineResult {
  mode: 'rule' | 'override';
  /** Unit cost leaf (EGP). */
  unitCost: Traced;
  /** The price before rounding (a rule's price; an override is its own value). */
  exactPrice: Traced;
  /** The price Sanad receives: the exact price rounded to 0.01. Its trace reaches the sourced inputs. */
  price: Traced;
  /** The same price as the decimal string sent to Sanad, always with two decimals ("50.00"; a trace value drops trailing zeros). */
  publishedPrice: string;
  /** price − unit cost (EGP per unit); negative below cost. */
  marginAmount: Traced;
  /** (price − cost) ÷ price × 100, 3 decimals. */
  marginOnPricePct: string;
  /** (price − cost) ÷ cost × 100, 3 decimals. */
  markupOnCostPct: string;
  belowCost: boolean;
}

function asBasis(basis: unknown): MarginBasis | null {
  return basis === 'markup_on_cost' || basis === 'margin_on_price' ? basis : null;
}

function resolveRule(
  rule: PriceRuleInput | undefined,
  policy: ConfidencePolicy,
  gaps: MissingData[],
  warnings: string[],
): { price: (cost: Traced) => Traced } | null {
  if (rule === undefined) {
    gaps.push({ property: 'priceList.rule', reason: PRICE_LIST_REASONS.SPEC_MISSING });
    return null;
  }
  const basis = asBasis(rule.basis);
  if (rule.basis === undefined) {
    gaps.push({ property: 'priceList.marginBasis', reason: INPUT_REASONS.MISSING });
  } else if (basis === null) {
    gaps.push({ property: 'priceList.marginBasis', reason: PRICE_LIST_REASONS.INVALID_BASIS });
  }
  const margin = resolveSourcedNumber(
    rule.marginPct,
    { property: 'priceList.marginPct', label: `Margin % (rule ${rule.key})`, unit: '%w/w', min: '0' },
    policy,
  );
  if (!margin.ok) {
    gaps.push(...margin.missing);
  } else {
    warnings.push(...margin.warnings);
    if (basis === 'margin_on_price' && margin.value.value.gte(100)) {
      gaps.push({ property: 'priceList.marginPct', reason: INPUT_REASONS.OUT_OF_RANGE });
    }
  }
  let distribution: { value: Dec; leaf: Traced } | null = null;
  if (rule.distributionCostPerUnit !== undefined) {
    const d = resolveSourcedNumber(
      rule.distributionCostPerUnit,
      { property: 'priceList.distributionCostPerUnit', label: `Distribution cost per unit (rule ${rule.key})`, unit: 'EGP', min: '0' },
      policy,
    );
    if (d.ok) {
      distribution = d.value;
      warnings.push(...d.warnings);
    } else {
      gaps.push(...d.missing);
    }
  }
  if (basis === null || !margin.ok || (basis === 'margin_on_price' && margin.value.value.gte(100))) {
    return null;
  }
  if (rule.distributionCostPerUnit !== undefined && distribution === null) {
    return null;
  }
  const m = margin.value;
  return {
    price: (cost) => {
      const withMargin = traced('margin', `Price = cost + margin (${basis})`, applyMargin(decOf(cost), m.value, basis), 'EGP', [cost, m.leaf]);
      if (distribution === null) {
        return withMargin;
      }
      return traced('add', 'Price = cost + margin + distribution', decOf(withMargin).plus(distribution.value), 'EGP', [withMargin, distribution.leaf]);
    },
  };
}

function resolveOverride(
  price: SourcedValue | undefined,
  policy: ConfidencePolicy,
  gaps: MissingData[],
  warnings: string[],
): Traced | null {
  const resolved = resolveSourcedNumber(price, { property: 'priceList.overridePrice', label: 'Agreed price (before tax)', unit: 'EGP', min: '0', minExclusive: true }, policy);
  if (!resolved.ok) {
    gaps.push(...resolved.missing);
    return null;
  }
  warnings.push(...resolved.warnings);
  return resolved.value.leaf;
}

/** Price of one list line (rule or override), its margin against the unit cost and the below-cost flag. All gaps are returned together. */
export function priceListLine(input: PriceListLineInput): Result<PriceListLineResult> {
  const gaps: MissingData[] = [];
  const warnings: string[] = [];

  const cost = resolveSourcedNumber(input.unitCost, { property: 'priceList.unitCost', label: 'Unit cost (EGP per unit)', unit: 'EGP', min: '0', minExclusive: true }, input.policy);
  if (!cost.ok) {
    gaps.push(...cost.missing);
  } else {
    warnings.push(...cost.warnings);
  }

  const spec = input.spec;
  let build: ((unitCost: Traced) => Traced) | null = null;
  if (spec === undefined) {
    gaps.push({ property: 'priceList.spec', reason: PRICE_LIST_REASONS.SPEC_MISSING });
  } else if (spec.mode === 'rule') {
    const rule = resolveRule(spec.rule, input.policy, gaps, warnings);
    build = rule === null ? null : rule.price;
  } else if (spec.mode === 'override') {
    const leaf = resolveOverride(spec.price, input.policy, gaps, warnings);
    build = leaf === null ? null : () => leaf;
  } else {
    gaps.push({ property: 'priceList.spec', reason: PRICE_LIST_REASONS.SPEC_INVALID });
  }

  if (gaps.length > 0 || !cost.ok || build === null || spec === undefined) {
    return missing(gaps, warnings);
  }

  const unitCost = cost.value.leaf;
  const exactPrice = build(unitCost);
  const published = roundToStep(decOf(exactPrice), DISPLAY_STEP.money);
  const price = traced('round', 'Published price (rounded to 0.01)', published, 'EGP', [exactPrice]);
  const margin = traced('sub', 'Margin = price − unit cost', decOf(price).minus(cost.value.value), 'EGP', [price, unitCost]);

  return ok(
    {
      mode: spec.mode,
      unitCost,
      exactPrice,
      price,
      publishedPrice: published,
      marginAmount: margin,
      marginOnPricePct: roundPercent(decOf(margin).div(decOf(price)).mul(100)),
      markupOnCostPct: roundPercent(decOf(margin).div(cost.value.value).mul(100)),
      belowCost: decOf(price).lt(cost.value.value),
    },
    warnings,
  );
}
