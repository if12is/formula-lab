import { dec, isDecimalString } from './decimal';
import { missing, ok, type Result } from './result';
import { currencyOf, Quantity, UnitMismatchError, type Currency } from './quantity';
import type { Confidence } from '../contract';

/** Explicit, dated exchange rate. There are no default rates anywhere in the engine. */
export interface FxRate {
  from: Currency;
  to: Currency;
  /** Units of `to` per 1 unit of `from`, decimal string. */
  rate: string;
  /** YYYY-MM-DD */
  date: string;
  sourceId: string;
  /** 'CBE' or another named source. */
  source: string;
  /** Confidence of the rate (shown on its leaf in the calculation tree). */
  confidence?: Confidence;
  /**
   * YYYY-MM-DD. When set, the rate was chosen (by the app's rate lookup) for prices dated that day and applies to no
   * other price. Without it the rate applies to every price in its currency. The engine never picks a rate by date itself.
   */
  forDate?: string;
}

export interface FxConversion {
  amount: Quantity<Currency>;
  rate: string;
  date: string;
  sourceId: string;
  source: string;
}

const ISO_DATE = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/;

/** True for a YYYY-MM-DD string (format check only, no calendar or clock access). */
export function isIsoDate(x: unknown): x is string {
  return typeof x === 'string' && ISO_DATE.test(x);
}

/**
 * Converts money using an explicit dated rate. Missing rate => Result missing;
 * currency mismatch or malformed rate => throws.
 */
export function convertMoney(
  amount: Quantity,
  to: Currency,
  rate: FxRate | undefined,
): Result<FxConversion> {
  const from = currencyOf(amount.unit);
  if (from === undefined) {
    throw new UnitMismatchError('fx', amount.unit, to);
  }
  if (rate === undefined) {
    return missing([{ property: `fx:${from}->${to}`, reason: 'dated exchange rate required (no default rates)' }]);
  }
  if (rate.from !== from || rate.to !== to) {
    throw new UnitMismatchError('fx', amount.unit, rate.from === from ? rate.to : rate.from);
  }
  if (!isDecimalString(rate.rate) || dec(rate.rate).lte(0)) {
    throw new RangeError(`fx rate must be a positive decimal string, got ${JSON.stringify(rate.rate)}`);
  }
  if (!isIsoDate(rate.date)) {
    throw new RangeError(`fx rate date must be YYYY-MM-DD, got ${JSON.stringify(rate.date)}`);
  }
  return ok({
    amount: new Quantity(amount.value.mul(rate.rate), to),
    rate: rate.rate,
    date: rate.date,
    sourceId: rate.sourceId,
    source: rate.source,
  });
}

/**
 * Display-only inverse of convertMoney: EGP shown in a foreign currency with that currency's dated rate (foreign -> EGP).
 * amount ÷ rate, at the engine's 34 significant digits. Costs are computed and stored in EGP; this figure only sits next
 * to the EGP one. Missing rate => Result missing (no default rates); a rate for another currency throws.
 */
export function convertFromEgp(
  amount: Quantity,
  to: Currency,
  rate: FxRate | undefined,
): Result<FxConversion> {
  if (currencyOf(amount.unit) !== 'EGP') {
    throw new UnitMismatchError('fx', amount.unit, 'EGP');
  }
  if (to === 'EGP') {
    throw new UnitMismatchError('fx', amount.unit, to);
  }
  if (rate === undefined) {
    return missing([{ property: `fx:${to}->EGP`, reason: 'dated exchange rate required (no default rates)' }]);
  }
  if (rate.from !== to || rate.to !== 'EGP') {
    throw new UnitMismatchError('fx', to, rate.from === to ? rate.to : rate.from);
  }
  if (!isDecimalString(rate.rate) || dec(rate.rate).lte(0)) {
    throw new RangeError(`fx rate must be a positive decimal string, got ${JSON.stringify(rate.rate)}`);
  }
  if (!isIsoDate(rate.date)) {
    throw new RangeError(`fx rate date must be YYYY-MM-DD, got ${JSON.stringify(rate.date)}`);
  }
  return ok({
    amount: new Quantity(amount.value.div(rate.rate), to),
    rate: rate.rate,
    date: rate.date,
    sourceId: rate.sourceId,
    source: rate.source,
  });
}
