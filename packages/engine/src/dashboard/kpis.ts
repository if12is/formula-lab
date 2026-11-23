/**
 * S-15: the decision figures of the home dashboard. Pure Decimal, no clock (the date is an input), no defaults: a figure that
 * cannot be worked out is `missing` with the reason, never 0. The app gathers facts (which prices exist, what each formula's
 * cost report and compliance inputs are) and the engine says what they add up to.
 *
 *   - stalePrices       materials whose newest price quote is older than the owner's threshold (no threshold = missing)
 *   - costQuality       gaps and low-confidence prices of a formula's cost report
 *   - priceImpact       what the price moves since the cost was approved did to a formula's material cost per kg
 *   - marginBound       the most the margin can move by that: the whole unit cost is taken as moving with the materials
 *   - formulaCompliance FAIL / UNKNOWN / PASS of a formula over the rule sets and the IFRA check that apply to it
 */
import type { Confidence, MissingData } from '../contract';
import { dec, isDecimalString } from '../core/decimal';
import { isIsoDate } from '../core/fx';
import type { CheckStatus } from '../core/check-status';
import { missing, ok, type Result } from '../core/result';
import { checkIfraCompliance, type IfraInput, type IfraReport } from '../compliance/ifra';
import { overallStatus, evaluateCompliance, type ComplianceReport, type ProductContext } from '../compliance/evaluate';
import type { FormulaComposition } from '../compliance/composition';
import type { RuleSet } from '../compliance/dsl';
import type { CostReport } from '../costing/cost-report';
import type { Candidate } from '../data/resolve';
import { CONFIDENCE_ORDER, DEFAULT_ALLOW_CONFIDENCE_BELOW } from '../trace/sourced';

export const DASHBOARD_REASONS = {
  STALE_THRESHOLD_NOT_SET: 'dashboard.stale_threshold_not_set',
  STALE_THRESHOLD_INVALID: 'dashboard.stale_threshold_invalid',
  AS_OF_INVALID: 'dashboard.as_of_invalid',
  NOTHING_TO_CHECK: 'dashboard.nothing_to_check',
  PRICE_MISSING: 'dashboard.price_missing',
  APPROVED_PRICE_INVALID: 'dashboard.approved_price_invalid',
  SELL_PRICE_MISSING: 'dashboard.sell_price_missing',
  UNIT_COST_MISSING: 'dashboard.unit_cost_missing',
  SELL_PRICE_NOT_POSITIVE: 'dashboard.sell_price_not_positive',
} as const;

// ------------------------------------------------------------------ stale prices

/** Whole days from `from` to `to` (both YYYY-MM-DD); negative when `from` is later. Calendar arithmetic only, no clock. */
export function daysBetween(from: string, to: string): number {
  const day = (iso: string): number => {
    const [y, m, d] = iso.split('-').map(Number) as [number, number, number];
    return Date.UTC(y, m - 1, d) / 86_400_000;
  };
  return day(to) - day(from);
}

export interface StaleMaterialInput {
  materialId: string;
  /** Date of the newest price quote on file; null = the material has no price at all. */
  latestQuotedOn: string | null;
}

export interface StalePrice {
  materialId: string;
  quotedOn: string;
  /** Days between the quote and `asOf`, decimal string. */
  ageDays: string;
}

export interface StalePrices {
  /** How many of the materials checked have a price older than the threshold. */
  staleCount: string;
  /** How many materials had a price to judge. */
  checkedCount: string;
  /** Oldest first, ties by id. */
  stale: StalePrice[];
  /** Materials with no price on file: not stale, MISSING (listed so they are not read as fresh). */
  unpriced: string[];
}

export function stalePrices(input: { asOf: string; thresholdDays: string | null; materials: readonly StaleMaterialInput[] }): Result<StalePrices> {
  const gaps: MissingData[] = [];
  if (!isIsoDate(input.asOf)) {
    gaps.push({ property: 'dashboard.asOf', reason: DASHBOARD_REASONS.AS_OF_INVALID });
  }
  if (input.thresholdDays === null) {
    gaps.push({ property: 'dashboard.stalePriceDays', reason: DASHBOARD_REASONS.STALE_THRESHOLD_NOT_SET });
  } else if (!isDecimalString(input.thresholdDays) || dec(input.thresholdDays).lt(0)) {
    gaps.push({ property: 'dashboard.stalePriceDays', reason: DASHBOARD_REASONS.STALE_THRESHOLD_INVALID });
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const threshold = dec(input.thresholdDays as string);
  const priced = input.materials.filter((m) => m.latestQuotedOn !== null && isIsoDate(m.latestQuotedOn));
  const stale = priced
    .map((m): StalePrice => ({ materialId: m.materialId, quotedOn: m.latestQuotedOn as string, ageDays: String(Math.max(0, daysBetween(m.latestQuotedOn as string, input.asOf))) }))
    .filter((p) => dec(p.ageDays).gt(threshold))
    .sort((a, b) => dec(b.ageDays).comparedTo(a.ageDays) || (a.materialId < b.materialId ? -1 : 1));
  const unpriced = input.materials.filter((m) => !priced.includes(m)).map((m) => m.materialId).sort();
  return ok({ staleCount: String(stale.length), checkedCount: String(priced.length), stale, unpriced });
}

// ------------------------------------------------------------------ cost quality

/** True for the level the engine's confidence policy warns at (L6 community) and everything worse; L7 is refused outright. */
export function isLowConfidence(confidence: Confidence): boolean {
  return CONFIDENCE_ORDER.indexOf(confidence) >= CONFIDENCE_ORDER.indexOf(DEFAULT_ALLOW_CONFIDENCE_BELOW);
}

export interface CostQuality {
  /** What the cost report could not price (its own `missing`). */
  missing: MissingData[];
  /** Lines costed with a low-confidence quote. */
  lowConfidence: { materialId: string; confidence: Confidence }[];
  /** EGP per kg of each material's chosen quote, for the lines that have one. */
  currentPrices: Record<string, string>;
}

export function costQuality(report: CostReport, quotes: Readonly<Record<string, readonly Candidate[]>>): CostQuality {
  const lowConfidence: CostQuality['lowConfidence'] = [];
  const currentPrices: Record<string, string> = {};
  for (const line of report.lines) {
    const quote = line.quoteIndex === null ? undefined : quotes[line.materialId]?.[line.quoteIndex];
    if (quote !== undefined && isLowConfidence(quote.confidence)) {
      lowConfidence.push({ materialId: line.materialId, confidence: quote.confidence });
    }
    if (line.priceEgpPerKg !== null) {
      currentPrices[line.materialId] = line.priceEgpPerKg;
    }
  }
  return { missing: report.missing, lowConfidence, currentPrices };
}

// ------------------------------------------------------------------ price impact

export interface ImpactLine {
  materialId: string;
  /** Percent w/w of the formula. */
  percentWW: string;
}

export interface PriceImpact {
  /** Σ p/100 × approved price, EGP per kg. */
  approvedCostPerKg: string;
  /** Σ p/100 × current price, EGP per kg. */
  currentCostPerKg: string;
  deltaPerKg: string;
  /** delta ÷ approved × 100; null when the approved cost is 0. */
  deltaPct: string | null;
}

export function priceImpact(lines: readonly ImpactLine[], approved: Readonly<Record<string, string>>, current: Readonly<Record<string, string | undefined>>): Result<PriceImpact> {
  const gaps: MissingData[] = [];
  let before = dec('0');
  let now = dec('0');
  for (const line of lines) {
    const a = approved[line.materialId];
    const c = current[line.materialId];
    if (a === undefined || !isDecimalString(a) || dec(a).lte(0)) {
      gaps.push({ materialId: line.materialId, property: 'price', reason: DASHBOARD_REASONS.APPROVED_PRICE_INVALID });
    } else if (c === undefined || !isDecimalString(c)) {
      gaps.push({ materialId: line.materialId, property: 'price', reason: DASHBOARD_REASONS.PRICE_MISSING });
    } else if (isDecimalString(line.percentWW)) {
      before = before.plus(dec(line.percentWW).div(100).mul(a));
      now = now.plus(dec(line.percentWW).div(100).mul(c));
    } else {
      gaps.push({ materialId: line.materialId, property: 'percentWW', reason: DASHBOARD_REASONS.PRICE_MISSING });
    }
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const delta = now.minus(before);
  return ok({ approvedCostPerKg: before.toFixed(), currentCostPerKg: now.toFixed(), deltaPerKg: delta.toFixed(), deltaPct: before.isZero() ? null : delta.div(before).mul(100).toFixed() });
}

export interface MarginBound {
  /** (sell − unit cost) ÷ sell × 100 now. */
  marginNowPct: string;
  /** The margin if the WHOLE unit cost moved by the materials' percent: the furthest it can go either way. */
  marginAtBoundPct: string;
  /** marginAtBound − marginNow, percentage points (negative = the margin shrinks). */
  deltaPoints: string;
}

/**
 * Packaging and conversion are not in the material change, so the share of the unit cost that moves with the materials is not
 * known here; it is at most all of it. So this is a bound on the effect, not the effect: the real change is between 0 and this.
 */
export function marginBound(input: { unitCost?: string | null; sellPrice?: string | null; materialDeltaPct?: string | null }): Result<MarginBound> {
  const gaps: MissingData[] = [];
  if (input.unitCost === undefined || input.unitCost === null || !isDecimalString(input.unitCost)) {
    gaps.push({ property: 'unitCost', reason: DASHBOARD_REASONS.UNIT_COST_MISSING });
  }
  if (input.sellPrice === undefined || input.sellPrice === null || !isDecimalString(input.sellPrice)) {
    gaps.push({ property: 'sellPrice', reason: DASHBOARD_REASONS.SELL_PRICE_MISSING });
  } else if (dec(input.sellPrice).lte(0)) {
    gaps.push({ property: 'sellPrice', reason: DASHBOARD_REASONS.SELL_PRICE_NOT_POSITIVE });
  }
  if (input.materialDeltaPct === undefined || input.materialDeltaPct === null || !isDecimalString(input.materialDeltaPct)) {
    gaps.push({ property: 'materialDeltaPct', reason: DASHBOARD_REASONS.PRICE_MISSING });
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const cost = dec(input.unitCost as string);
  const sell = dec(input.sellPrice as string);
  const now = sell.minus(cost).div(sell).mul(100);
  const bound = sell.minus(cost.mul(dec(input.materialDeltaPct as string).div(100).plus(1))).div(sell).mul(100);
  return ok({ marginNowPct: now.toFixed(), marginAtBoundPct: bound.toFixed(), deltaPoints: bound.minus(now).toFixed() });
}

// ------------------------------------------------------------------ compliance

export interface FormulaComplianceInput {
  composition: FormulaComposition;
  ruleSets: readonly RuleSet[];
  context: ProductContext;
  /** The IFRA check of the fragrance oils in the formula, or absent when it has none. */
  ifra?: IfraInput;
}

export interface FormulaCompliance {
  /** FAIL if any check fails, else UNKNOWN if any cannot be decided, else PASS; NOT_APPLICABLE when no check applies (never PASS). */
  overall: CheckStatus | 'NOT_APPLICABLE';
  /** Rule sets that could not be read (invalid), counted as UNKNOWN. */
  invalidRuleSets: string[];
  failedRuleIds: string[];
  unknownRuleIds: string[];
}

export function formulaCompliance(input: FormulaComplianceInput): FormulaCompliance {
  const statuses: CheckStatus[] = [];
  const invalid: string[] = [];
  const failed: string[] = [];
  const unknown: string[] = [];
  for (const ruleSet of input.ruleSets) {
    const result = evaluateCompliance(input.composition, ruleSet, input.context);
    if (!result.ok) {
      invalid.push(ruleSet.id);
      statuses.push('UNKNOWN');
      continue;
    }
    const report: ComplianceReport = result.value;
    const applicable = report.results.filter((r) => r.status !== 'NOT_APPLICABLE');
    if (applicable.length > 0) {
      statuses.push(report.overall);
    }
    for (const r of applicable) {
      if (r.status === 'FAIL') {
        failed.push(`${ruleSet.id}:${r.ruleId}`);
      } else if (r.status === 'UNKNOWN') {
        unknown.push(`${ruleSet.id}:${r.ruleId}`);
      }
    }
  }
  if (input.ifra !== undefined) {
    const report: IfraReport = checkIfraCompliance(input.ifra);
    statuses.push(report.overall);
    for (const r of report.results) {
      if (r.status === 'FAIL') {
        failed.push(`ifra:${r.materialId}`);
      } else if (r.status === 'UNKNOWN') {
        unknown.push(`ifra:${r.materialId}`);
      }
    }
  }
  return { overall: statuses.length === 0 ? 'NOT_APPLICABLE' : overallStatus(statuses), invalidRuleSets: invalid, failedRuleIds: failed, unknownRuleIds: unknown };
}
