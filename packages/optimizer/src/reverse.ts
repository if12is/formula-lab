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
 * The math lives in ONE place, the engine's price waterfall (`@fl/engine` pricing/waterfall.ts, I-05); this
 * module only fixes the optimizer's message keys (`reversePricing.*`, `optimizer.reverse.*`).
 * The unit is whatever the shelf price is quoted per (pack, kg or L); `fillKg` is kg of product in it.
 */
import { backwardWaterfall, type BackwardWaterfallInput, type BackwardWaterfallResult, type MarginBasis, type Result, type WaterfallStep } from '@fl/engine';

export type { MarginBasis };

/** Same fields as the engine's backward waterfall, without the optional returns/promo lines. */
export type ReversePricingInput = Omit<BackwardWaterfallInput, 'returnsPct' | 'promoPct'>;

export type ReverseStep = WaterfallStep;

export type ReversePricingResult = BackwardWaterfallResult;

export const REVERSE_REASONS = {
  NON_POSITIVE: 'optimizer.reverse.non_positive_price',
  INVALID_BASIS: 'optimizer.reverse.invalid_margin_basis',
} as const;

export function reversePricing(input: ReversePricingInput): Result<ReversePricingResult> {
  return backwardWaterfall(input, {
    propertyPrefix: 'reversePricing.',
    stepKeyPrefix: 'optimizer.reverse.step.',
    nonPositiveReason: REVERSE_REASONS.NON_POSITIVE,
    invalidBasisReason: REVERSE_REASONS.INVALID_BASIS,
  });
}
