import { D, dec, type Dec } from './decimal';
import { currencyOf, Quantity, UnitMismatchError } from './quantity';

/**
 * Display rounding (plan/02-specs/rounding-policy.md). ROUND_HALF_EVEN, once, at display time.
 * Everything returns decimal strings with the step's number of decimals (e.g. "12.0" for step 0.1).
 */
export const DISPLAY_STEP = {
  massG: '0.1',
  volumeML: '1',
  percent: '0.001',
  money: '0.01',
  phHlb: '0.1',
} as const;

function assertPositiveStep(step: Dec): void {
  if (step.lte(0)) {
    throw new RangeError('rounding step must be positive');
  }
}

/** Rounds to the nearest multiple of `step` (half-even). Exact Decimal result. */
export function quantize(value: string | Dec, step: string | Dec): Dec {
  const s = dec(step);
  assertPositiveStep(s);
  return dec(value).div(s).toDecimalPlaces(0, D.ROUND_HALF_EVEN).mul(s);
}

function fixedFor(value: Dec, step: Dec): string {
  return value.toFixed(Math.max(step.decimalPlaces(), 0));
}

/** quantize() formatted with the step's decimals. */
export function roundToStep(value: string | Dec, step: string | Dec): string {
  return fixedFor(quantize(value, step), dec(step));
}

/** Mass in grams; `scaleResolutionG` defaults to 0.1 g (weighing sheet passes the registered scale). */
export function roundMass(mass: Quantity, scaleResolutionG: string | Dec = DISPLAY_STEP.massG): string {
  if (mass.dimension !== 'mass') {
    throw new UnitMismatchError('roundMass', mass.unit, 'g');
  }
  return roundToStep(mass.to('g').value, scaleResolutionG);
}

/** Volume in millilitres, 1 mL. */
export function roundVolume(volume: Quantity): string {
  if (volume.dimension !== 'volume') {
    throw new UnitMismatchError('roundVolume', volume.unit, 'mL');
  }
  return roundToStep(volume.to('mL').value, DISPLAY_STEP.volumeML);
}

/** Percent value (already in %), 0.001%. */
export function roundPercent(percent: string | Dec): string {
  return roundToStep(percent, DISPLAY_STEP.percent);
}

/** Money in its own currency, 0.01. */
export function roundMoney(money: Quantity): string {
  if (currencyOf(money.unit) === undefined) {
    throw new UnitMismatchError('roundMoney', money.unit, 'EGP');
  }
  return roundToStep(money.value, DISPLAY_STEP.money);
}

/** pH or HLB, 0.1. */
export function roundPhHlb(value: string | Dec): string {
  return roundToStep(value, DISPLAY_STEP.phHlb);
}

export interface RoundingResidual {
  roundedParts: string[];
  sumOfRoundedParts: string;
  roundedTotal: string;
  /** roundedTotal - sumOfRoundedParts; "0.0"-style zero when the display adds up. */
  residual: string;
  hasResidual: boolean;
}

/**
 * Reports the display residual when rounded parts do not add up to the rounded total.
 * `total` must be computed from UNROUNDED parts by the caller; nothing is redistributed.
 */
export function roundingResidual(
  parts: readonly (string | Dec)[],
  total: string | Dec,
  step: string | Dec,
): RoundingResidual {
  const s = dec(step);
  const rounded = parts.map((p) => quantize(p, s));
  const sum = rounded.reduce((acc, p) => acc.plus(p), new D(0));
  const roundedTotal = quantize(total, s);
  const residual = roundedTotal.minus(sum);
  return {
    roundedParts: rounded.map((p) => fixedFor(p, s)),
    sumOfRoundedParts: fixedFor(sum, s),
    roundedTotal: fixedFor(roundedTotal, s),
    residual: fixedFor(residual, s),
    hasResidual: !residual.isZero(),
  };
}
