import type { MissingData } from '../contract';

/** Calculation outcome: a value, or the complete list of what is missing. Never a silent zero. */
export type Result<T> =
  | { ok: true; value: T; warnings: string[] }
  | { ok: false; missing: MissingData[]; warnings: string[] };

export function ok<T>(value: T, warnings: string[] = []): Result<T> {
  return { ok: true, value, warnings };
}

/** Failure result; at least one missing item is required (ok=false with nothing missing is a bug). */
export function missing<T = never>(items: MissingData[], warnings: string[] = []): Result<T> {
  if (items.length === 0) {
    throw new RangeError('missing(): at least one MissingData item is required');
  }
  return { ok: false, missing: items, warnings };
}

export function map<T, U>(r: Result<T>, fn: (value: T) => U): Result<U> {
  return r.ok ? ok(fn(r.value), r.warnings) : r;
}

/** Sequential dependency: fn only runs when r is ok. Use all()/combine() for independent inputs. */
export function flatMap<T, U>(r: Result<T>, fn: (value: T) => Result<U>): Result<U> {
  if (!r.ok) {
    return r;
  }
  const next = fn(r.value);
  const warnings = [...r.warnings, ...next.warnings];
  return next.ok ? ok(next.value, warnings) : { ok: false, missing: next.missing, warnings };
}

/** Collects every result of a homogeneous list; accumulates ALL missing items and warnings. */
export function combine<T>(results: readonly Result<T>[]): Result<T[]> {
  const warnings: string[] = [];
  const gaps: MissingData[] = [];
  const values: T[] = [];
  for (const r of results) {
    warnings.push(...r.warnings);
    if (r.ok) {
      values.push(r.value);
    } else {
      gaps.push(...r.missing);
    }
  }
  return gaps.length > 0 ? missing(gaps, warnings) : ok(values, warnings);
}

type Values<T extends readonly Result<unknown>[]> = {
  -readonly [K in keyof T]: T[K] extends Result<infer V> ? V : never;
};

/** Tuple version of combine(): keeps each position's type. Never short-circuits. */
export function all<T extends readonly Result<unknown>[]>(results: T): Result<Values<T>> {
  return combine(results as readonly Result<unknown>[]) as Result<Values<T>>;
}
