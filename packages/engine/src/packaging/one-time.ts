/**
 * H-05: one-time costs (printing plates / clichés, moulds, design) spread over a planned quantity:
 *   per unit = amount ÷ planned units
 * Also the engine of the regulatory fees in I-03 (EDA notification, IFRA certificate ...).
 * Amount and planned units are sourced inputs; either one missing is a gap, never "no cost".
 */
import type { SourcedValue } from '../contract';
import type { SourcedScalar } from '../core/parse';
import { missing, ok, type Result } from '../core/result';
import { sourcedEgp, type MoneyContext } from '../cost/money';
import { duplicateIds, lineCurrency, resolveScalar, sumTraced, type CostLine } from '../costing/line';
import { all } from '../core/result';
import { decOf, traced, type Traced } from '../trace/trace';

export interface OneTimeCostInput {
  id: string;
  description?: string;
  /** Total amount, dated and sourced (EGP, or another currency with a dated rate). */
  amount?: SourcedValue;
  /** Planned quantity (units) the amount is spread over; > 0. */
  plannedUnits?: SourcedScalar;
}

export interface AmortizedCost {
  lines: CostLine[];
  /** EGP per unit. */
  total: Traced;
}

export const ONE_TIME_REASONS = {
  DUPLICATE: 'one_time.duplicate_id',
} as const;

/** `lineKeyPrefix` names the family of lines (`one_time`, `regulatory`); `title` is the sum label. */
export function amortizeOneTimeCosts(
  items: readonly OneTimeCostInput[],
  ctx: MoneyContext,
  lineKeyPrefix = 'one_time',
  title = 'One-time costs per unit = Σ amount ÷ planned units',
): Result<AmortizedCost> {
  const dups = duplicateIds(items.map((i) => i.id), 'id', ONE_TIME_REASONS.DUPLICATE);
  const lines = items.map((item): Result<CostLine> => {
    const parts = all([
      sourcedEgp(item.id, 'amount', `${item.id} amount (EGP)`, item.amount, ctx),
      resolveScalar(item.id, 'plannedUnits', `${item.id} planned units`, item.plannedUnits, 'pc', ctx, { minExclusive: true }),
    ] as const);
    if (!parts.ok) {
      return parts;
    }
    const [amount, planned] = parts.value;
    const perUnit = traced('div', `${item.id} per unit = amount ÷ planned units`, decOf(amount).div(planned.value), 'EGP', [amount, planned.leaf]);
    return ok({ key: `${lineKeyPrefix}:${item.id}`, label: item.description ?? item.id, perUnit, currency: lineCurrency(item.amount as SourcedValue) }, parts.warnings);
  });
  const warnings = lines.flatMap((l) => l.warnings);
  const gaps = [...dups, ...lines.flatMap((l) => (l.ok ? [] : l.missing))];
  if (gaps.length > 0) {
    return missing(gaps, warnings);
  }
  const okLines = lines.map((l) => (l as { ok: true; value: CostLine }).value);
  return ok({ lines: okLines, total: sumTraced(title, okLines.map((l) => l.perUnit)) }, warnings);
}
