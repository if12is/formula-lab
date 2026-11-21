import { describe, expect, it } from 'vitest';
import { all, combine, flatMap, map, missing, ok, type Result } from './result';

const need = (property: string): Result<string> =>
  missing([{ property, reason: `${property} missing` }]);

describe('Result', () => {
  it('ok / missing basics', () => {
    expect(ok(1)).toEqual({ ok: true, value: 1, warnings: [] });
    expect(missing([{ property: 'p', reason: 'r' }], ['w'])).toEqual({
      ok: false,
      missing: [{ property: 'p', reason: 'r' }],
      warnings: ['w'],
    });
  });
  it('refuses ok=false with nothing missing', () => {
    expect(() => missing([])).toThrow(RangeError);
  });
  it('map keeps warnings and short-circuits failures', () => {
    expect(map(ok(2, ['w']), (x) => x * 2)).toEqual({ ok: true, value: 4, warnings: ['w'] });
    const f = need('a');
    expect(map(f, (x) => x + '!')).toBe(f);
  });
  it('flatMap chains and accumulates warnings', () => {
    const r = flatMap(ok(2, ['a']), (x) => ok(x + 1, ['b']));
    expect(r).toEqual({ ok: true, value: 3, warnings: ['a', 'b'] });
    const bad = flatMap(ok(2, ['a']), () => missing<number>([{ property: 'p', reason: 'r' }], ['b']));
    expect(bad).toEqual({ ok: false, missing: [{ property: 'p', reason: 'r' }], warnings: ['a', 'b'] });
    const first = need('x');
    expect(flatMap(first, () => ok(1))).toBe(first);
  });
  it('combine / all collect values', () => {
    expect(combine([ok(1), ok(2, ['w'])])).toEqual({ ok: true, value: [1, 2], warnings: ['w'] });
    const t = all([ok(1), ok('a')] as const);
    expect(t.ok && t.value).toEqual([1, 'a']);
  });
  it('a calc with 2 missing inputs reports both and never returns zero', () => {
    const cost = (price: string | undefined, density: string | undefined): Result<string> => {
      const p: Result<string> = price === undefined ? need('price') : ok(price);
      const d: Result<string> = density === undefined ? need('density') : ok(density);
      return map(all([p, d] as const), ([a, b]) => a + b);
    };
    const r = cost(undefined, undefined);
    expect(r.ok).toBe(false);
    if (!r.ok) {
      expect(r.missing.map((m) => m.property)).toEqual(['price', 'density']);
    }
    expect('value' in r).toBe(false);
    const half = cost('1', undefined);
    expect(half.ok).toBe(false);
    expect(cost('1', '2')).toEqual({ ok: true, value: '12', warnings: [] });
  });
});
