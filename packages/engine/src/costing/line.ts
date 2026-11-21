/** Shared building blocks of the per-unit cost modules (packaging, conversion, quality, unit cost). */
import type { MissingData, SourcedValue } from '../contract';
import { currencyOf, type Currency, type Unit } from '../core/quantity';
import type { SourcedScalar } from '../core/parse';
import { dec } from '../core/decimal';
import type { Result } from '../core/result';
import type { MoneyContext } from '../cost/money';
import { resolveSourcedNumber, type ResolvedNumber } from '../sourced-input';
import { decOf, traced, type Traced } from '../trace/trace';

/** Currency of the price a line is built on (after FX the line is always EGP). */
export type LineCurrency = Currency;

/** One per-unit cost line: EGP per selling unit, with the currency of the underlying price. */
export interface CostLine {
  /** Unique within a unit cost, e.g. `packaging:<itemId>`. */
  key: string;
  label: string;
  perUnit: Traced;
  currency: LineCurrency;
  /** Item kind or cost kind (container, labour, ...), when the input names one. */
  kind?: string;
}

/** Currency of an already validated money value. */
export function lineCurrency(sv: SourcedValue): LineCurrency {
  return currencyOf(sv.unit) ?? 'EGP';
}

export interface ScalarBounds {
  min?: string;
  minExclusive?: boolean;
  max?: string;
}

/**
 * A sourced non-money number (quantity per unit, minutes, kWh, percent, planned units).
 * Quantities with no engine unit (minutes, kWh, litres of water per batch) carry `pc` on the leaf: the basis
 * is in the label and in the field name. A unit declared on the input must equal `unit`.
 */
export function resolveScalar(
  id: string | undefined,
  property: string,
  label: string,
  sv: SourcedScalar | undefined,
  unit: Unit,
  ctx: MoneyContext,
  bounds: ScalarBounds = {},
): Result<ResolvedNumber> {
  return resolveSourcedNumber(
    sv,
    { materialId: id, property, label, unit, min: bounds.min ?? '0', minExclusive: bounds.minExclusive, max: bounds.max },
    ctx.policy,
  );
}

/** Σ of EGP trace nodes (an empty list sums to an explicit zero). */
export function sumTraced(label: string, items: readonly Traced[]): Traced {
  return traced('sum', label, items.reduce((acc, t) => acc.plus(decOf(t)), dec('0')), 'EGP', items);
}

/** One gap per id that appears more than once (a key must identify exactly one line). */
export function duplicateIds(ids: readonly string[], property: string, reason: string): MissingData[] {
  const seen = new Set<string>();
  const dup = new Set<string>();
  for (const id of ids) {
    (seen.has(id) ? dup : seen).add(id);
  }
  return [...dup].map((id) => ({ materialId: id, property, reason }));
}

/** Gaps of a failed result, or none. */
export function gapsOf<T>(r: Result<T>): MissingData[] {
  return r.ok ? [] : r.missing;
}
