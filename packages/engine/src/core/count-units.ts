import { dec, type Dec } from './decimal';
import { missing, ok, type Result } from './result';

export type CountUnit = 'piece' | 'carton' | 'pallet';

/** Packing configuration; positive integers as strings or Decimals. Missing fields => Result missing. */
export interface PackingConfig {
  unitsPerCarton?: string | Dec;
  cartonsPerPallet?: string | Dec;
}

/** Whole units plus remainder (floor, never ceil). All values are integer decimal strings. */
export interface WholeAndRemainder {
  whole: string;
  remainder: string;
  /** Unit the remainder is expressed in. */
  remainderUnit: CountUnit;
}

function asInteger(x: string | Dec, what: string, positive: boolean): Dec {
  const d = dec(x);
  if (!d.isInteger() || (positive ? d.lte(0) : d.isNegative())) {
    throw new RangeError(`${what} must be a ${positive ? 'positive' : 'non-negative'} integer, got ${d.toFixed()}`);
  }
  return d;
}

function needConfig(value: string | Dec | undefined, property: string): Result<Dec> {
  if (value === undefined) {
    return missing([{ property, reason: 'packing configuration required' }]);
  }
  return ok(asInteger(value, property, true));
}

function split(count: Dec, per: Dec, remainderUnit: CountUnit): WholeAndRemainder {
  const whole = count.div(per).floor();
  return { whole: whole.toFixed(), remainder: count.minus(whole.mul(per)).toFixed(), remainderUnit };
}

export function piecesToCartons(pieces: string | Dec, cfg: PackingConfig): Result<WholeAndRemainder> {
  const per = needConfig(cfg.unitsPerCarton, 'unitsPerCarton');
  const n = asInteger(pieces, 'pieces', false);
  return per.ok ? ok(split(n, per.value, 'piece')) : per;
}

export function cartonsToPieces(cartons: string | Dec, cfg: PackingConfig): Result<string> {
  const per = needConfig(cfg.unitsPerCarton, 'unitsPerCarton');
  const n = asInteger(cartons, 'cartons', false);
  return per.ok ? ok(n.mul(per.value).toFixed()) : per;
}

export function cartonsToPallets(cartons: string | Dec, cfg: PackingConfig): Result<WholeAndRemainder> {
  const per = needConfig(cfg.cartonsPerPallet, 'cartonsPerPallet');
  const n = asInteger(cartons, 'cartons', false);
  return per.ok ? ok(split(n, per.value, 'carton')) : per;
}

export function palletsToCartons(pallets: string | Dec, cfg: PackingConfig): Result<string> {
  const per = needConfig(cfg.cartonsPerPallet, 'cartonsPerPallet');
  const n = asInteger(pallets, 'pallets', false);
  return per.ok ? ok(n.mul(per.value).toFixed()) : per;
}

/** pieces -> whole pallets + remainder pieces (needs both configs; reports every missing one). */
export function piecesToPallets(pieces: string | Dec, cfg: PackingConfig): Result<WholeAndRemainder> {
  const perCarton = needConfig(cfg.unitsPerCarton, 'unitsPerCarton');
  const perPallet = needConfig(cfg.cartonsPerPallet, 'cartonsPerPallet');
  const n = asInteger(pieces, 'pieces', false);
  if (!perCarton.ok || !perPallet.ok) {
    return missing([
      ...(perCarton.ok ? [] : perCarton.missing),
      ...(perPallet.ok ? [] : perPallet.missing),
    ]);
  }
  return ok(split(n, perCarton.value.mul(perPallet.value), 'piece'));
}

export function palletsToPieces(pallets: string | Dec, cfg: PackingConfig): Result<string> {
  const perCarton = needConfig(cfg.unitsPerCarton, 'unitsPerCarton');
  const perPallet = needConfig(cfg.cartonsPerPallet, 'cartonsPerPallet');
  const n = asInteger(pallets, 'pallets', false);
  if (!perCarton.ok || !perPallet.ok) {
    return missing([
      ...(perCarton.ok ? [] : perCarton.missing),
      ...(perPallet.ok ? [] : perPallet.missing),
    ]);
  }
  return ok(n.mul(perCarton.value).mul(perPallet.value).toFixed());
}

