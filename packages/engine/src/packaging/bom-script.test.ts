import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

// S-12: the script the BOM editor's cost preview runs (scripts/packaging-bom-cost.ts), through its real stdin/stdout contract.
// TEST-FIXTURE numbers (fictional prices, chosen so every figure is exact).
const run = (input: unknown) =>
  spawnSync('node', ['--experimental-transform-types', '--no-warnings', '--import', './scripts/node-ts/register.mjs', 'scripts/packaging-bom-cost.ts'], {
    cwd: new URL('../..', import.meta.url),
    input: typeof input === 'string' ? input : JSON.stringify(input),
    encoding: 'utf8',
  });

const sourced = (value: string, extra: object = {}) => ({ value, sourceId: 'src-1', confidence: 'L2_supplier_tds', validFrom: '2026-11-01', ...extra });
const item = (itemId: string, kind: string, price: string, extra: object = {}) => ({
  itemId, kind, level: 'unit', price: sourced(price, { unit: 'EGP' }), qtyPerUnit: sourced('1'), rejectPct: sourced('0'), ...extra,
});
const pair = { containerNeck: '24/410', closureNeck: '24/410 PP', sourceId: 'src-2', confidence: 'L3_standard', validFrom: '2026-11-01' };

describe('S-12 packaging-bom-cost script', () => {
  it('prices a BOM with reject, a carton share and a declared closure pair: 3 / 0.8 + 1 + 24 / 12 = 6.75', () => {
    const result = run({
      items: [
        item('bottle', 'container', '3', { rejectPct: sourced('20'), neckFinish: '24/410' }),
        item('cap', 'closure', '1', { neckFinish: '24/410 PP' }),
        item('carton', 'shipping_carton', '24', { level: 'carton' }),
      ],
      packing: { unitsPerCarton: '12' },
      pairs: [pair],
    });

    expect(result.status).toBe(0);
    const answer = JSON.parse(result.stdout);
    expect(answer.ok).toBe(true);
    expect(answer.value.total.value).toBe('6.75');
    expect(answer.value.lines.map((l: { key: string; perUnit: { value: string } }) => [l.key, l.perUnit.value])).toEqual([['packaging:bottle', '3.75'], ['packaging:cap', '1'], ['packaging:carton', '2']]);
  });

  it('lists the gaps and gives no total: a missing price, a carton without packing, a closure with no declared pair (its fit is the gap)', () => {
    const noPrice = item('label', 'label', '1');
    delete (noPrice as { price?: unknown }).price;
    const result = run({
      items: [item('bottle', 'container', '3', { neckFinish: '24/410' }), item('cap', 'closure', '1', { neckFinish: '24/410 PP' }), item('carton', 'shipping_carton', '24', { level: 'carton' }), noPrice],
      pairs: [],
    });

    const answer = JSON.parse(result.stdout);
    expect(answer.ok).toBe(false);
    expect(answer.value).toBeUndefined();
    expect(answer.missing.map((m: { materialId?: string; property: string }) => [m.materialId, m.property]).sort()).toEqual(
      [['cap', 'compatibility'], ['carton', 'unitsPerCarton'], ['label', 'price']].sort(),
    );
  });

  it('exits non-zero, with a message, when stdin is not JSON or lacks items and pairs', () => {
    expect(run('not json').status).toBe(2);
    expect(run({ items: [] }).status).toBe(2);
    expect(run({ items: [], pairs: [] }).stdout).not.toBe('');
  });
});
