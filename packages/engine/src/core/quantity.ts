import { dec, type Dec } from './decimal';

export type Dimension =
  | 'mass'
  | 'volume'
  | 'fraction'
  | 'acid-value'
  | 'money'
  | 'viscosity'
  | 'density'
  | 'count';

export type MassUnit = 'kg' | 'g' | 'mg';
export type VolumeUnit = 'L' | 'mL';
export type DensityUnit = 'g/mL' | 'kg/L';
/** Currencies the engine can hold money in. A non-EGP amount becomes EGP only through a dated rate (core/fx.ts). */
export const CURRENCIES = ['EGP', 'USD', 'EUR', 'CNY', 'SAR', 'AED', 'GBP'] as const;
export type Currency = (typeof CURRENCIES)[number];
export type Unit =
  | MassUnit
  | VolumeUnit
  | DensityUnit
  | '%w/w'
  | '%w/v'
  | '%v/v'
  | 'mgKOH/g'
  | Currency
  | 'mPa·s'
  | 'pc';

interface UnitDef {
  dimension: Dimension;
  /** Units are interconvertible only inside the same group (e.g. %w/w never mixes with %w/v, EGP never with USD). */
  group: string;
  /** Exact factor to the group's base unit, as a decimal string. */
  factor: string;
  /** Present for money: the ISO currency. */
  currency?: Currency;
}

const UNIT_DEFS: Record<Unit, UnitDef> = {
  kg: { dimension: 'mass', group: 'mass', factor: '1000' },
  g: { dimension: 'mass', group: 'mass', factor: '1' },
  mg: { dimension: 'mass', group: 'mass', factor: '0.001' },
  L: { dimension: 'volume', group: 'volume', factor: '1000' },
  mL: { dimension: 'volume', group: 'volume', factor: '1' },
  'g/mL': { dimension: 'density', group: 'density', factor: '1' },
  'kg/L': { dimension: 'density', group: 'density', factor: '1' },
  '%w/w': { dimension: 'fraction', group: '%w/w', factor: '1' },
  '%w/v': { dimension: 'fraction', group: '%w/v', factor: '1' },
  '%v/v': { dimension: 'fraction', group: '%v/v', factor: '1' },
  'mgKOH/g': { dimension: 'acid-value', group: 'mgKOH/g', factor: '1' },
  EGP: { dimension: 'money', group: 'EGP', factor: '1', currency: 'EGP' },
  USD: { dimension: 'money', group: 'USD', factor: '1', currency: 'USD' },
  EUR: { dimension: 'money', group: 'EUR', factor: '1', currency: 'EUR' },
  CNY: { dimension: 'money', group: 'CNY', factor: '1', currency: 'CNY' },
  SAR: { dimension: 'money', group: 'SAR', factor: '1', currency: 'SAR' },
  AED: { dimension: 'money', group: 'AED', factor: '1', currency: 'AED' },
  GBP: { dimension: 'money', group: 'GBP', factor: '1', currency: 'GBP' },
  'mPa·s': { dimension: 'viscosity', group: 'mPa·s', factor: '1' },
  /** Dimensionless piece count (whole packs). */
  pc: { dimension: 'count', group: 'count', factor: '1' },
};

export const UNITS = Object.keys(UNIT_DEFS) as Unit[];

export function isUnit(x: unknown): x is Unit {
  return typeof x === 'string' && Object.prototype.hasOwnProperty.call(UNIT_DEFS, x);
}

export function dimensionOf(unit: Unit): Dimension {
  return UNIT_DEFS[unit].dimension;
}

/** The ISO currency of a money unit, or undefined for non-money units. */
export function currencyOf(unit: Unit): Currency | undefined {
  return UNIT_DEFS[unit].currency;
}

export type OperationName = 'add' | 'sub' | 'compare' | 'convert';

/** Thrown when an operation mixes incompatible units, dimensions or currencies. */
export class UnitMismatchError extends Error {
  constructor(
    public readonly operation: OperationName | string,
    public readonly left: Unit,
    public readonly right: Unit,
  ) {
    super(`Unit mismatch in ${operation}: ${left} vs ${right}`);
    this.name = 'UnitMismatchError';
  }
}

export interface QuantityJSON<U extends Unit = Unit> {
  value: string;
  unit: U;
}

function assertSameGroup(operation: string, a: Unit, b: Unit): void {
  if (UNIT_DEFS[a].group !== UNIT_DEFS[b].group) {
    throw new UnitMismatchError(operation, a, b);
  }
}

/** Immutable exact amount with a unit. */
export class Quantity<U extends Unit = Unit> {
  readonly value: Dec;
  readonly unit: U;

  constructor(value: string | Dec, unit: U) {
    if (!isUnit(unit)) {
      throw new TypeError(`Unknown unit: ${String(unit)}`);
    }
    this.value = dec(value);
    this.unit = unit;
    Object.freeze(this);
  }

  get dimension(): Dimension {
    return dimensionOf(this.unit);
  }

  /** Converts inside the same dimension/group (g<->kg<->mg, L<->mL); anything else throws UnitMismatchError. */
  to<V extends Unit>(unit: V): Quantity<V> {
    assertSameGroup('convert', this.unit, unit);
    const value = this.value.mul(UNIT_DEFS[this.unit].factor).div(UNIT_DEFS[unit].factor);
    return new Quantity(value, unit);
  }

  /** Result keeps the left operand's unit. */
  add(other: Quantity): Quantity<U> {
    assertSameGroup('add', this.unit, other.unit);
    return new Quantity(this.value.plus(other.to(this.unit).value), this.unit);
  }

  sub(other: Quantity): Quantity<U> {
    assertSameGroup('sub', this.unit, other.unit);
    return new Quantity(this.value.minus(other.to(this.unit).value), this.unit);
  }

  compare(other: Quantity): -1 | 0 | 1 {
    assertSameGroup('compare', this.unit, other.unit);
    return this.value.cmp(other.to(this.unit).value) as -1 | 0 | 1;
  }

  equals(other: Quantity): boolean {
    return this.compare(other) === 0;
  }

  /** Multiply by a dimensionless factor. */
  mul(factor: string | Dec): Quantity<U> {
    return new Quantity(this.value.mul(dec(factor)), this.unit);
  }

  /** Divide by a non-zero dimensionless divisor. */
  div(divisor: string | Dec): Quantity<U> {
    const d = dec(divisor);
    if (d.isZero()) {
      throw new RangeError('Quantity.div: division by zero');
    }
    return new Quantity(this.value.div(d), this.unit);
  }

  toJSON(): QuantityJSON<U> {
    return { value: this.value.toFixed(), unit: this.unit };
  }

  static fromJSON(json: QuantityJSON): Quantity {
    return new Quantity(json.value, json.unit);
  }
}

export function qty<U extends Unit>(value: string | Dec, unit: U): Quantity<U> {
  return new Quantity(value, unit);
}
