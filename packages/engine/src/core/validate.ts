/**
 * Shared helpers for the strict JSON validators (compliance rules DSL, decision rules DSL).
 * Validators collect every problem as MissingData (path in `property`, reason key in `reason`).
 */
import type { MissingData } from '../contract';

export type Issues = MissingData[];

export function isRecord(x: unknown): x is Record<string, unknown> {
  return typeof x === 'object' && x !== null && !Array.isArray(x);
}

export function nonEmptyString(x: unknown): x is string {
  return typeof x === 'string' && x.trim() !== '';
}

/** True when a JS number appears anywhere in a parsed JSON value (numbers must travel as decimal strings). */
export function containsJsNumber(x: unknown): boolean {
  if (typeof x === 'number') {
    return true;
  }
  if (Array.isArray(x)) {
    return x.some(containsJsNumber);
  }
  return isRecord(x) && Object.values(x).some(containsJsNumber);
}

/** Reports every key of `obj` that is not in `allowed`, using the caller's reason key. */
export function checkKeys(obj: Record<string, unknown>, allowed: readonly string[], path: string, issues: Issues, reason: string): void {
  for (const key of Object.keys(obj)) {
    if (!allowed.includes(key)) {
      issues.push({ property: `${path}.${key}`, reason });
    }
  }
}
