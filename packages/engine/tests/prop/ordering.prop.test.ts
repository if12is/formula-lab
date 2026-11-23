/**
 * T-02 properties of determinism: the result never depends on the key order of the input, on the order measured points are
 * listed in, or on the order production lines arrive in.
 */
import fc from 'fast-check';
import { describe, expect, it } from 'vitest';
import {
  ENGINE_VERSION,
  calculate,
  canonicalJson,
  inputHash,
  reagentForTargetPH,
  saltForViscosity,
  sha256Hex,
  viscosityAt,
  weighingSheet,
  type EngineInput,
} from '../../src/index';
import { formulaLines, params, price, reorderKeys } from './arbitraries';

/** Shuffle driven by fast-check data, so it is reproducible from the seed. */
const permutation = (n: number) => fc.shuffledSubarray(Array.from({ length: n }, (_, i) => i), { minLength: n, maxLength: n });
const apply = <T>(items: readonly T[], order: readonly number[]): T[] => order.map((i) => items[i] as T);

describe('property: canonical JSON and hashes ignore key order', () => {
  const json = fc.object({ key: fc.string({ maxLength: 6 }), values: [fc.string({ maxLength: 8 }), fc.boolean(), fc.constant(null)], maxDepth: 3, maxKeys: 6 });

  it('canonicalJson(reordered) = canonicalJson(original), so their SHA-256 agree', () => {
    fc.assert(
      fc.property(json, fc.nat(9), (value, rotate) => {
        const reordered = reorderKeys(value, rotate);
        expect(canonicalJson(reordered)).toBe(canonicalJson(value));
        expect(sha256Hex(canonicalJson(reordered))).toBe(sha256Hex(canonicalJson(value)));
      }),
      params,
    );
  });
});

describe('property: calculate() is a pure function of the input', () => {
  const priced = formulaLines.chain((lines) => fc.tuple(fc.constant(lines), fc.array(price, { minLength: lines.length, maxLength: lines.length })));
  const inputOf = (lines: { materialId: string; percentWW: string }[], prices: string[]): EngineInput => ({
    engineVersion: ENGINE_VERSION,
    datasetVersion: 'prop',
    formula: { lines },
    batch: { size: { value: '40', unit: 'kg' }, wastagePct: '10', packSize: { value: '250', unit: 'g' } },
    prices: Object.fromEntries(lines.map((l, i) => [l.materialId, { value: prices[i]!, unit: 'EGP' as const, sourceId: `S-${l.materialId}`, confidence: 'L2_supplier_tds' as const, validFrom: '2026-01-01' }])),
    packaging: { perPack: { value: '1.5', unit: 'EGP', sourceId: 'S-PACK', confidence: 'L2_supplier_tds', validFrom: '2026-01-01' } },
    policy: { rounding: 'default' },
  });

  it('two calls on the same input, and a call on the same input with every key re-ordered, give identical hashes and outputs', () => {
    fc.assert(
      fc.property(priced, fc.nat(9), ([lines, prices], rotate) => {
        const input = inputOf(lines, prices);
        const a = calculate(input);
        const b = calculate(input);
        const c = calculate(reorderKeys(input, rotate));
        expect(b).toEqual(a);
        expect(c.inputHash).toBe(a.inputHash);
        expect(c.outputHash).toBe(a.outputHash);
        expect(inputHash(reorderKeys(input, rotate))).toBe(inputHash(input));
      }),
      params,
    );
  });

  it('listing the formula lines in another order does not change a single figure (only the trace order may differ)', () => {
    fc.assert(
      fc.property(priced.chain(([lines, prices]) => fc.tuple(fc.constant(lines), fc.constant(prices), permutation(lines.length))), ([lines, prices, order]) => {
        const a = calculate(inputOf(lines, prices));
        const b = calculate(inputOf(apply(lines, order), apply(prices, order)));
        const figures = (o: typeof a) => Object.fromEntries(Object.entries(o.results).map(([k, v]) => [k, v.value]));
        expect(figures(b)).toEqual(figures(a));
      }),
      params,
    );
  });

  it('missing[] has a stable order: the same incomplete input twice gives the same list', () => {
    fc.assert(
      fc.property(priced, ([lines, prices]) => {
        const input = inputOf(lines, prices);
        const rest = { ...input.prices };
        delete rest[lines[0]!.materialId];
        delete rest[lines[1]!.materialId];
        const incomplete = { ...input, prices: rest };
        expect(calculate(incomplete).missing).toEqual(calculate(incomplete).missing);
        expect(calculate(incomplete).missing.flatMap((m) => (m.materialId === undefined ? [] : [m.materialId]))).toEqual([lines[0]!.materialId, lines[1]!.materialId]);
      }),
      params,
    );
  });
});

describe('property: measured curves do not depend on the order the points were typed in', () => {
  const xs = fc.uniqueArray(fc.integer({ min: 0, max: 50 }), { minLength: 3, maxLength: 8 });
  const curve = xs.chain((x) => fc.tuple(fc.constant(x), fc.array(fc.integer({ min: 0, max: 100_000 }), { minLength: x.length, maxLength: x.length }), permutation(x.length)));

  it('viscosityAt and saltForViscosity are identical for a shuffled point list', () => {
    fc.assert(
      fc.property(curve, fc.integer({ min: 0, max: 50 }), fc.integer({ min: 0, max: 100_000 }), ([x, y, order], query, target) => {
        const points = x.map((s, i) => ({ saltPct: String(s), viscosity_mPas: String(y[i]), sourceId: `L${i}`, confidence: 'L1_coa_batch' as const }));
        const shuffled = apply(points, order);
        expect(viscosityAt(shuffled, String(query))).toEqual(viscosityAt(points, String(query)));
        expect(saltForViscosity(shuffled, String(target))).toEqual(saltForViscosity(points, String(target)));
      }),
      params,
    );
  });

  it('reagentForTargetPH is identical for a shuffled titration curve', () => {
    fc.assert(
      fc.property(curve, fc.integer({ min: 0, max: 14 }), ([x, y, order], target) => {
        const points = x.map((a, i) => ({ added: String(a), pH: String(y[i]! % 15), sourceId: `T${i}`, confidence: 'L1_coa_batch' as const }));
        const run = (p: typeof points) => reagentForTargetPH({ curve: { reagentId: 'ACID', unit: 'pctWW', points: p }, targetPH: String(target) });
        expect(run(apply(points, order))).toEqual(run(points));
      }),
      params,
    );
  });
});

describe('property: the weighing sheet does not depend on the order lines arrive in', () => {
  const lineSet = fc
    .array(fc.integer({ min: 1, max: 1000 }), { minLength: 2, maxLength: 7 })
    .map((weights) => {
      const total = weights.reduce((a, b) => a + b, 0);
      const parts = weights.map((w) => Math.floor((w * 100_000) / total));
      parts[0] = (parts[0] as number) + (100_000 - parts.reduce((a, b) => a + b, 0));
      return parts.map((p, i) => ({
        lineNo: String(i + 1),
        materialId: `M${i}`,
        percentWW: (p / 1000).toFixed(3),
        phase: (['water', 'oil', 'cool_down'] as const)[i % 3],
      }));
    });

  it('same sheet, same sheetHash, for any arrival order (lineNo is unique, so the printed order is fully determined)', () => {
    fc.assert(
      fc.property(lineSet.chain((lines) => fc.tuple(fc.constant(lines), permutation(lines.length))), fc.integer({ min: 1, max: 5000 }), ([lines, order], kg) => {
        const run = (ls: typeof lines) => weighingSheet({ lines: ls, batchMass: { value: String(kg), unit: 'kg' }, resolutionG: '0.5' });
        const a = run(lines);
        const b = run(apply(lines, order));
        expect(b).toEqual(a);
      }),
      params,
    );
  });
});
