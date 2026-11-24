import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

// P-11: the air-care-blend and formula-phases operations of scripts/assistant.ts, through the script's real stdin/stdout contract.
// TEST-FIXTURE numbers (fictional percents, chosen so every figure is exact).
const run = (op: string, input: unknown) =>
  spawnSync('node', ['--experimental-transform-types', '--no-warnings', '--import', './scripts/node-ts/register.mjs', 'scripts/assistant.ts'], {
    cwd: new URL('../..', import.meta.url),
    input: JSON.stringify({ op, input }),
    encoding: 'utf8',
  });
const answer = (op: string, input: unknown) => JSON.parse(run(op, input).stdout);
const limit = { value: '12', unit: '%w/w', sourceId: 'cert-1', confidence: 'L1_coa_batch', validFrom: '2026-03-01' };

describe('P-11 assistant script: air-care-blend', () => {
  it('splits a 500 g batch: 10 % oil + 2 % other leaves 88 % carrier, and the sourced IFRA limit passes with a margin of 2', () => {
    const result = answer('air-care-blend', { fragranceOilPct: '10', otherPct: '2', batchMassG: '500', ifraLimitPct: limit });

    expect(result.ok).toBe(true);
    expect([result.value.fragranceOil.mass.value, result.value.carrier.pct.value, result.value.carrier.mass.value]).toEqual(['50', '88', '440']);
    expect(result.value.ifra).toMatchObject({ status: 'PASS', marginPct: { value: '2' }, limitPct: { value: '12', sourceId: 'cert-1', confidence: 'L1_coa_batch' } });
  });

  it('is UNKNOWN, never PASS, without a sourced limit, and reports percents that leave no carrier', () => {
    expect(answer('air-care-blend', { fragranceOilPct: '10', batchMassG: '500' }).value.ifra.status).toBe('UNKNOWN');
    const over = answer('air-care-blend', { fragranceOilPct: '60', otherPct: '40', batchMassG: '500' });
    expect(over.ok).toBe(false);
    expect(over.missing[0].property).toBe('fragranceOilPct');
  });
});

describe('P-11 assistant script: formula-phases', () => {
  it('balances the q.s. water line and sums each phase', () => {
    const result = answer('formula-phases', { lines: [
      { materialId: 'w', qs: true, phase: 'water' }, { materialId: 'o', percentWW: '25', phase: 'oil' }, { materialId: 'c', percentWW: '5', phase: 'cool_down' },
    ] });

    expect(result.ok).toBe(true);
    expect(result.value.totals.map((t: { phase: string; sum: string }) => [t.phase, t.sum])).toEqual([['water', '70'], ['oil', '25'], ['cool_down', '5']]);
    expect(result.value.withinTolerance).toBe(true);
  });

  it('names a formula with no phase, a line without one and phase sums that are not 100', () => {
    expect(answer('formula-phases', { lines: [{ materialId: 'a', percentWW: '100' }] }).missing).toEqual([{ property: 'phase', reason: 'formula.phase_missing' }]);
    expect(answer('formula-phases', { lines: [{ materialId: 'a', percentWW: '60', phase: 'water' }, { materialId: 'b', percentWW: '40' }] }).missing).toEqual([
      { materialId: 'b', property: 'phase', reason: 'formula.phase_missing' },
    ]);
    expect(answer('formula-phases', { lines: [{ materialId: 'a', percentWW: '60', phase: 'water' }, { materialId: 'b', percentWW: '30', phase: 'oil' }] }).missing[0]).toMatchObject({ property: 'phaseSum' });
  });
});
