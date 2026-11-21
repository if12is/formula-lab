import { dec, isDecimalString } from './decimal';
import { missing, ok, type Result } from './result';
import { currencyOf, Quantity, UnitMismatchError, type Currency } from './quantity';

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
}

export interface FxConversion {
  amount: Quantity<Currency>;
  rate: string;
  date: string;
  sourceId: string;
  source: string;
}

const ISO_DATE = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/;

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
  if (!ISO_DATE.test(rate.date)) {
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
