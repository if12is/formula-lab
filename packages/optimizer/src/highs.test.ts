import { readFileSync } from 'node:fs';
import { beforeAll, describe, expect, it } from 'vitest';
import { buildModel, costObjective, renderLp } from './build';
import { HIGHS_OPTIONS, HIGHS_PACKAGE_VERSION, loadHighs, solveLp, solverInfo } from './highs';
import { CASE2_MATERIALS, CASE2_PROFILE } from './test-fixtures';
import { dec } from './util';

const json = (rel: string): Record<string, Record<string, string> & string> =>
  JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8')) as Record<string, Record<string, string> & string>;

beforeAll(async () => {
  await loadHighs();
});

describe('J-03 HiGHS wrapper', () => {
  it('pins the exact npm version and it matches the installed package', () => {
    const own = json('../package.json');
    expect(own.dependencies?.highs).toBe(HIGHS_PACKAGE_VERSION);
    expect(own.dependencies?.highs).toMatch(/^\d+\.\d+\.\d+$/);
    expect(json('../node_modules/highs/package.json').version).toBe(HIGHS_PACKAGE_VERSION);
  });

  it('records deterministic options and the solver version', () => {
    expect(solverInfo()).toEqual({
      name: 'highs-wasm',
      packageVersion: HIGHS_PACKAGE_VERSION,
      options: { parallel: 'off', presolve: 'on', random_seed: '0', simplex_strategy: '1', solver: 'simplex', threads: '1' },
      timeLimit: 'none',
    });
    expect(Object.keys(HIGHS_OPTIONS)).not.toContain('time_limit');
  });

  it('solves a tiny LP exactly as computed by hand', () => {
    // min 3a + 2b, a + b = 1, 0.5a + 0.9b >= 0.6  ->  b = 1, cost 2 (b alone gives 0.9 >= 0.6)
    const sol = solveLp('Minimize\n obj: 3 a + 2 b\nSubject To\n s: a + b = 1\n c: 0.5 a + 0.9 b >= 0.6\nBounds\n 0 <= a <= 1\n 0 <= b <= 1\nEnd\n');
    expect(sol.status).toBe('optimal');
    expect(sol.primal.get('a')).toBeCloseTo(0, 12);
    expect(sol.primal.get('b')).toBeCloseTo(1, 12);
    expect(sol.rowDual.get('s')).toBe(3);
  });

  it('reports infeasible models as infeasible', () => {
    expect(solveLp('Minimize\n obj: x\nSubject To\n c: x >= 2\nBounds\n 0 <= x <= 1\nEnd\n').status).toBe('infeasible');
  });

  it('gives the identical solution in 1000 runs', () => {
    const model = buildModel({ profile: CASE2_PROFILE, materials: CASE2_MATERIALS.map((m) => ({ materialId: m.materialId, pricePerKg: dec(m.price?.value ?? '0'), activePct: dec(m.activeMatterPct?.value ?? '0'), functions: m.functions?.tags ?? [] })), qsMaterialId: 'WATER', targetCostCapPerKg: null });
    const text = renderLp(model, { objective: costObjective(model) });
    const key = (): string => {
      const s = solveLp(text);
      return JSON.stringify([s.status, [...s.primal], [...s.rowDual], [...s.reducedCost]]);
    };
    const first = key();
    for (let i = 0; i < 999; i += 1) {
      expect(key()).toBe(first);
    }
  });
});
