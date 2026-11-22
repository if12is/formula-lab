import { describe, expect, it } from 'vitest';
import type { Candidate } from '../data/resolve';
import type { FxRate } from '../core/fx';
import { buildCostReport } from './cost-report';

// Fictional TEST inputs, chosen so every figure is exact by hand.
const quote = (value: string, over: Partial<Candidate> = {}): Candidate => ({ value, unit: 'EGP', sourceId: 'S1', confidence: 'L2_supplier_tds', validFrom: '2026-01-01', ...over });
const usd: FxRate = { from: 'USD', to: 'EGP', rate: '50', date: '2026-02-01', sourceId: 'FX1', source: 'TEST' };
const base = { datasetVersion: 'test' };

describe('I-08 cost report', () => {
  it('case 1: A 60 % at 10 EGP + B 40 % at 0.5 USD x 50 = 25 => 6 + 10 = 16 EGP/kg, each line with its price and tree', () => {
    const r = buildCostReport({
      ...base,
      lines: [{ materialId: 'A', percentWW: '60' }, { materialId: 'B', percentWW: '40' }],
      quotes: { A: [quote('10')], B: [quote('0.5', { unit: 'USD', sourceId: 'S2', validFrom: '2026-02-01' })] },
      fxRates: [usd],
    });
    expect(r.ok).toBe(true);
    expect(r.total?.value).toBe('16');
    expect(r.lines.map((l) => [l.materialId, l.priceEgpPerKg, l.contributionEgpPerKg, l.quoteIndex])).toEqual([['A', '10', '6', 0], ['B', '25', '10', 0]]);
    // The total's tree has one child per line, and B's tree reaches the exchange rate.
    expect(r.total?.trace.inputs).toHaveLength(2);
    expect(JSON.stringify(r.lines[1]?.trace)).toContain('FX1');
  });

  it('case 2: a q.s. line is balanced first: A 30 % at 20, water q.s. (70 %) at 2 => 6 + 1.4 = 7.4', () => {
    const r = buildCostReport({
      ...base,
      lines: [{ materialId: 'A', percentWW: '30' }, { materialId: 'W', qs: true }],
      quotes: { A: [quote('20')], W: [quote('2')] },
    });
    expect(r.ok).toBe(true);
    expect(r.lines[1]).toMatchObject({ percentWW: '70', qs: true, contributionEgpPerKg: '1.4' });
    expect(r.total?.value).toBe('7.4');
  });

  it('case 3: a missing price is a gap, never zero: the other line keeps its price, no total, no contribution', () => {
    const r = buildCostReport({
      ...base,
      lines: [{ materialId: 'A', percentWW: '50' }, { materialId: 'B', percentWW: '50' }],
      quotes: { A: [quote('10')], B: [] },
    });
    expect(r.ok).toBe(false);
    expect(r.total).toBeNull();
    expect(r.missing.map((m) => [m.materialId, m.property])).toContainEqual(['B', 'price']);
    expect(r.lines[0]).toMatchObject({ priceEgpPerKg: '10', contributionEgpPerKg: null });
    expect(r.lines[1]).toMatchObject({ priceEgpPerKg: null, quoteIndex: null });
  });

  it('case 4: the better-confidence quote is used and the other is listed as rejected: L2 at 12 beats L5 at 9 => 50 % x 12 = 6', () => {
    const r = buildCostReport({
      ...base,
      lines: [{ materialId: 'A', percentWW: '50' }, { materialId: 'B', percentWW: '50' }],
      quotes: { A: [quote('9', { confidence: 'L5_reference', sourceId: 'S9' }), quote('12')], B: [quote('0')] },
    });
    expect(r.lines[0]).toMatchObject({ quoteIndex: 1, contributionEgpPerKg: '6' });
    expect(r.lines[0]?.rejected).toEqual([{ quoteIndex: 0, reason: 'lower confidence', detail: 'L5_reference < L2_supplier_tds' }]);
  });

  it('case 5: an L7 estimate alone is not costed with (needs a signed override): gap on that line', () => {
    const r = buildCostReport({ ...base, lines: [{ materialId: 'A', percentWW: '100' }], quotes: { A: [quote('10', { confidence: 'L7_estimate' })] } });
    expect(r.ok).toBe(false);
    expect(r.missing.some((m) => m.materialId === 'A' && m.reason.includes('l7'))).toBe(true);
  });

  it('a formula that does not add up to 100 % is refused as a whole, and the price of each line still shows', () => {
    const r = buildCostReport({ ...base, lines: [{ materialId: 'A', percentWW: '60' }], quotes: { A: [quote('10')] } });
    expect(r.ok).toBe(false);
    expect(r.missing.some((m) => m.property === 'formula')).toBe(true);
    expect(r.lines[0]?.priceEgpPerKg).toBe('10');
  });

  it('is deterministic: the same input gives the same hashes, whatever the key order of quotes', () => {
    const lines = [{ materialId: 'A', percentWW: '100' }];
    const a = buildCostReport({ ...base, lines, quotes: { A: [quote('10')] } });
    const b = buildCostReport({ datasetVersion: 'test', quotes: { A: [quote('10')] }, lines });
    expect([a.inputHash, a.outputHash]).toEqual([b.inputHash, b.outputHash]);
  });
});
