/**
 * Shared generators of the property suite (T-02). Numbers always travel as decimal STRINGS (the engine rejects JS numbers).
 * Fixed seed and run count so CI is reproducible; a failure prints the seed and the shrunk counter-example.
 */
import fc from 'fast-check';

export const RUNS = 500;
export const SEED = 20261123;
export const params = { seed: SEED, numRuns: RUNS } as const;

/** n / 10^scale as an exact decimal string (integer arithmetic only). */
export function fromScaled(n: bigint | number, scale: number): string {
  const neg = BigInt(n) < 0n;
  const s = (neg ? -BigInt(n) : BigInt(n)).toString().padStart(scale + 1, '0');
  const text = scale === 0 ? s : `${s.slice(0, -scale)}.${s.slice(-scale)}`;
  return neg ? `-${text}` : text;
}

/** A positive price with 2 decimals, 0.01 .. 100000.00. */
export const price = fc.integer({ min: 1, max: 10_000_000 }).map((n) => fromScaled(n, 2));

/** A non-negative increment with 2 decimals, 0 .. 1000.00 (zero included on purpose). */
export const delta = fc.integer({ min: 0, max: 100_000 }).map((n) => fromScaled(n, 2));

/**
 * 2..8 formula percentages (3 decimals) that add up to exactly 100.000: positive integer weights are normalised to
 * 100000 thousandths with the remainder added to the first line, so no line is zero and the sum is exact.
 */
export const percentList = fc
  .array(fc.integer({ min: 1, max: 1000 }), { minLength: 2, maxLength: 8 })
  .map((weights) => {
    const total = weights.reduce((a, b) => a + b, 0);
    const parts = weights.map((w) => Math.floor((w * 100_000) / total));
    parts[0] = (parts[0] as number) + (100_000 - parts.reduce((a, b) => a + b, 0));
    return parts.map((p) => fromScaled(p, 3));
  });

export const formulaLines = percentList.map((ps) => ps.map((percentWW, i) => ({ materialId: `M${i}`, percentWW })));

/** Reorders object keys recursively with a rotation (a deterministic stand-in for "any key order"). */
export function reorderKeys<T>(value: T, rotate: number): T {
  if (Array.isArray(value)) {
    return value.map((v) => reorderKeys(v, rotate)) as unknown as T;
  }
  if (value !== null && typeof value === 'object') {
    const keys = Object.keys(value as Record<string, unknown>);
    const k = keys.length === 0 ? 0 : rotate % keys.length;
    const rotated = [...keys.slice(k), ...keys.slice(0, k)].reverse();
    return Object.fromEntries(rotated.map((key) => [key, reorderKeys((value as Record<string, unknown>)[key], rotate)])) as T;
  }
  return value;
}
