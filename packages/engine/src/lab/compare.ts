/**
 * Expected (computed) against measured (O-05), e.g. the active matter the formula should have against the MBAS or
 * titration result. Reports the signed absolute difference (measured - expected) and the relative difference as a % of the
 * expected value. It decides NOTHING: there is no tolerance, no pass/fail, no status (acceptance limits are the owner's,
 * see HUMAN-TODO). Either side missing => missing; every gap is listed. A zero expectation has no relative difference
 * (reported through a warning, the absolute one stays). Pure, decimal.js only.
 */
import type { MissingData, SourcedValue } from '../contract';
import { dec } from '../core/decimal';
import { parseDec } from '../core/parse';
import { missing, ok, type Result } from '../core/result';
import { decOf, inputLeaf, sourcedLeaf, traced, type Traced } from '../trace/trace';

export const EXPECTED_MEASURED_REASONS = {
  EXPECTED_MISSING: 'compare.expected_missing',
  MEASURED_MISSING: 'compare.measured_missing',
  UNIT_MISMATCH: 'compare.unit_mismatch',
  INVALID_MEASURED: 'compare.invalid_measured_value',
  EXPECTED_ZERO: 'compare.relative_undefined_expected_zero',
} as const;

export interface ExpectedMeasured {
  expected: Traced;
  measured: Traced;
  /** measured - expected, in the unit of the quantity (negative: measured below expected). */
  difference: Traced;
  /** (measured - expected) / expected x 100, in %; absent when the expected value is zero. */
  relativePct?: Traced;
}

export function compareExpectedMeasured(property: string, expected: Traced | undefined, measured: SourcedValue | undefined): Result<ExpectedMeasured> {
  const gaps: MissingData[] = [];
  if (expected === undefined) {
    gaps.push({ property, reason: EXPECTED_MEASURED_REASONS.EXPECTED_MISSING });
  }
  if (measured === undefined) {
    gaps.push({ property, reason: EXPECTED_MEASURED_REASONS.MEASURED_MISSING });
  }
  if (expected === undefined || measured === undefined) {
    return missing(gaps);
  }
  if (parseDec(measured.value) === null) {
    return missing([{ property, reason: EXPECTED_MEASURED_REASONS.INVALID_MEASURED }]);
  }
  if (measured.unit !== expected.unit) {
    return missing([{ property, reason: `${EXPECTED_MEASURED_REASONS.UNIT_MISMATCH}: ${expected.unit} vs ${measured.unit}` }]);
  }
  const m = sourcedLeaf(`Measured ${property}`, measured);
  const diff = dec(measured.value).minus(decOf(expected));
  const difference = traced('sub', 'Difference = measured - expected', diff, expected.unit, [m, expected]);
  const out: ExpectedMeasured = { expected, measured: m, difference };
  if (decOf(expected).isZero()) {
    return ok(out, [EXPECTED_MEASURED_REASONS.EXPECTED_ZERO]);
  }
  out.relativePct = traced('div', 'Relative difference (%) = difference / expected x 100', diff.div(decOf(expected)).mul(100), '%', [
    difference,
    expected,
    inputLeaf('Percent factor', '100', '%'),
  ]);
  return ok(out);
}
