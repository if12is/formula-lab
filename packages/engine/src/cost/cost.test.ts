import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import type { FxRate } from '../core/fx';
import { leavesOf, inputLeaf } from '../trace/trace';
import { costOfBatch, costPerLitre, costPerNetKg, costPerPack, packagingOfBatch, priceByMargin } from './derived';
import { materialCostPerKg } from './material';
import { sourcedEgp } from './money';

const price = (value: string, extra: Partial<SourcedValue> = {}): SourcedValue => ({
  value, unit: 'EGP', sourceId: 'SRC', confidence: 'L2_supplier_tds', validFrom: '2026-01-01', ...extra,
});
const L = (materialId: string, percentWW: string) => ({ materialId, percentWW });
const usd: FxRate = { from: 'USD', to: 'EGP', rate: '48', date: '2026-02-01', sourceId: 'FX-1', source: 'TEST' };

describe('materialCostPerKg', () => {
  const lines = [L('MAT-A', '60'), L('MAT-B', '40')];
  const prices = { 'MAT-A': price('10.00', { sourceId: 'SRC-A' }), 'MAT-B': price('25.50', { sourceId: 'SRC-B', confidence: 'L1_coa_batch' }) };

  it('C_kg = Σ p/100 × P  (6 + 10.2 = 16.2)', () => {
    const r = materialCostPerKg({ lines, prices, policy: {} });
    expect(r.ok && r.value.value).toBe('16.2');
  });

  it('F-10: the trace contains every price leaf with sourceId and confidence', () => {
    const r = materialCostPerKg({ lines, prices, policy: {} });
    if (!r.ok) {
      throw new Error('expected ok');
    }
    const priceLeaves = leavesOf(r.value.trace).filter((n) => n.label.endsWith('price (EGP/kg)'));
    expect(priceLeaves.map((n) => [n.label, n.value, n.sourceId, n.confidence])).toEqual([
      ['MAT-A price (EGP/kg)', '10', 'SRC-A', 'L2_supplier_tds'],
      ['MAT-B price (EGP/kg)', '25.5', 'SRC-B', 'L1_coa_batch'],
    ]);
    expect(r.value.trace.op).toBe('sum');
    expect(r.value.trace.inputs).toHaveLength(2);
  });

  it('lists EVERY material without a price, not just the first', () => {
    const r = materialCostPerKg({ lines: [L('A', '30'), L('B', '30'), L('C', '40')], prices: { B: price('1') }, policy: {} });
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing.map((m) => [m.materialId, m.property])).toEqual([['A', 'price'], ['C', 'price']]);
  });

  it('prototype keys are not prices', () => {
    const r = materialCostPerKg({ lines: [L('constructor', '100')], prices: {}, policy: {} });
    expect(r.ok).toBe(false);
  });

  it('USD price needs a dated rate; traced through an fx node', () => {
    const p = { A: price('2', { unit: 'USD' }) };
    const none = materialCostPerKg({ lines: [L('A', '100')], prices: p, policy: {} });
    expect(!none.ok && none.missing[0]).toMatchObject({ materialId: 'A', property: 'fx:USD->EGP' });
    const r = materialCostPerKg({ lines: [L('A', '100')], prices: p, fxRates: [usd], policy: {} });
    expect(r.ok && r.value.value).toBe('96');
    if (r.ok) {
      const leaves = leavesOf(r.value.trace);
      expect(leaves.map((n) => n.sourceId)).toContain('FX-1');
    }
  });

  it('every supported currency converts through its own dated rate and never through another currency\'s', () => {
    for (const currency of ['EUR', 'CNY', 'SAR', 'AED', 'GBP'] as const) {
      const p = { A: price('2', { unit: currency }) };
      const wrong = materialCostPerKg({ lines: [L('A', '100')], prices: p, fxRates: [usd], policy: {} });
      expect(!wrong.ok && wrong.missing[0]).toMatchObject({ materialId: 'A', property: `fx:${currency}->EGP` });
      const rate: FxRate = { from: currency, to: 'EGP', rate: '10.5', date: '2026-02-01', sourceId: 'FX-2', source: 'TEST' };
      const r = materialCostPerKg({ lines: [L('A', '100')], prices: p, fxRates: [usd, rate], policy: {} });
      expect(r.ok && r.value.value).toBe('21');
    }
  });

  it('a rate chosen for a price date (forDate) applies to prices of that day only; no other rate means missing', () => {
    const jan: FxRate = { ...usd, rate: '40', date: '2026-01-02', forDate: '2026-01-05' };
    const feb: FxRate = { ...usd, rate: '50', date: '2026-02-01', forDate: '2026-02-03' };
    const prices = { A: price('1', { unit: 'USD', validFrom: '2026-01-05' }), B: price('1', { unit: 'USD', validFrom: '2026-02-03' }), C: price('1', { unit: 'USD', validFrom: '2026-03-01' }) };
    const both = materialCostPerKg({ lines: [L('A', '50'), L('B', '50')], prices, fxRates: [jan, feb], policy: {} });
    expect(both.ok && both.value.value).toBe('45');
    const gap = materialCostPerKg({ lines: [L('A', '50'), L('C', '50')], prices, fxRates: [jan, feb], policy: {} });
    expect(!gap.ok && gap.missing).toEqual([{ materialId: 'C', property: 'fx:USD->EGP', reason: 'cost.fx_rate_required' }]);
  });

  it('the rate leaf names the rate, its date and source, and carries its source id and confidence', () => {
    const rate: FxRate = { ...usd, date: '2026-01-30', source: 'CBE', sourceId: 'SRC-CBE', confidence: 'L4_regulatory_db' };
    const r = materialCostPerKg({ lines: [L('A', '100')], prices: { A: price('2', { unit: 'USD' }) }, fxRates: [rate], policy: {} });
    if (!r.ok) {
      throw new Error('expected ok');
    }
    const leaf = leavesOf(r.value.trace).find((n) => n.sourceId === 'SRC-CBE');
    expect(leaf).toMatchObject({ label: 'FX USD->EGP 2026-01-30 (CBE)', value: '48', confidence: 'L4_regulatory_db' });
  });

  it('low confidence warns; L7 is missing without override and allowed with one', () => {
    const lines1 = [L('A', '100')];
    const warn = materialCostPerKg({ lines: lines1, prices: { A: price('1', { confidence: 'L5_reference' }) }, policy: { allowConfidenceBelow: 'L3_standard' } });
    expect(warn.ok && warn.warnings).toEqual(['confidence.below_policy:A:price:L5_reference']);
    const l7 = { A: price('1', { confidence: 'L7_estimate' }) };
    expect(materialCostPerKg({ lines: lines1, prices: l7, policy: {} }).ok).toBe(false);
    const signed = { overrides: [{ materialId: 'A', property: 'price', signedBy: 'chem', reason: 'quote' }] };
    const r = materialCostPerKg({ lines: lines1, prices: l7, policy: signed });
    expect(r.ok && r.warnings).toEqual(['confidence.l7_override:A:price']);
  });
});

describe('sourcedEgp validation', () => {
  const ctx = { policy: {} };
  it('missing, non-money, negative and undated are all missing', () => {
    expect(sourcedEgp('A', 'price', 'p', undefined, ctx).ok).toBe(false);
    expect(sourcedEgp('A', 'price', 'p', price('1', { unit: 'kg' }), ctx).ok).toBe(false);
    expect(sourcedEgp('A', 'price', 'p', price('-1'), ctx).ok).toBe(false);
    expect(sourcedEgp('A', 'price', 'p', price('1', { validFrom: 'yesterday' }), ctx).ok).toBe(false);
  });
  it('zero is a legitimate sourced price', () => {
    const r = sourcedEgp('W', 'price', 'p', price('0'), ctx);
    expect(r.ok && r.value.value).toBe('0');
  });
});

describe('derived costs', () => {
  const kg = inputLeaf('C_kg', '16.2', 'EGP');
  it('wastage, litre, pack, batch', () => {
    const net = costPerNetKg(kg, '20');
    expect(net.ok && net.value.value).toBe('20.25');
    expect(costPerNetKg(kg, '100').ok).toBe(false);
    expect(costPerLitre(kg, inputLeaf('rho', '1.02', 'kg/L')).value).toBe('16.524');
    expect(costPerPack(kg, inputLeaf('m', '0.51', 'kg'), inputLeaf('pack', '3', 'EGP')).value).toBe('11.262');
    const pk = packagingOfBatch(inputLeaf('N', '100', 'pc'), inputLeaf('pack', '3', 'EGP'));
    expect(pk.value).toBe('300');
    const b = costOfBatch(kg, inputLeaf('gross', '125', 'kg'), pk);
    expect([b.materials.value, b.batch.value]).toEqual(['2025', '2325']);
  });
  it('margin: Price = C/(1 - m/100); m >= 100 or garbage is an error', () => {
    const p = priceByMargin(kg, '20');
    expect(p.ok && p.value.value).toBe('20.25');
    expect(priceByMargin(kg, '0').ok && priceByMargin(kg, '0')).toMatchObject({ ok: true });
    expect(priceByMargin(kg, '-5')).toMatchObject({ ok: false, missing: [{ reason: 'cost.margin_must_be_non_negative' }] });
    for (const m of ['100', '120', 'x']) {
      expect(priceByMargin(kg, m).ok).toBe(false);
    }
  });
});
