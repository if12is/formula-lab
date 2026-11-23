import { describe, expect, it } from 'vitest';
import type { Candidate, FxRate } from '@fl/engine';
import { offeredMaterials, withProfileSource, type MaterialQuotes } from './app-input';
import { optimize } from './optimize';
import { profile } from './test-fixtures';

/** TEST-FIXTURE data: every figure is fictional and chosen to be hand-checkable. */
const T = { sourceId: 'TEST-FIXTURE', validFrom: '2026-01-01' } as const;
const quote = (value: string, unit: Candidate['unit'], confidence: Candidate['confidence'] = 'L2_supplier_tds', validFrom: string = T.validFrom): Candidate => ({ value, unit, sourceId: T.sourceId, confidence, validFrom });
const active = (value: string): Candidate[] => [{ value, unit: '%w/w', sourceId: T.sourceId, confidence: 'L2_supplier_tds', validFrom: T.validFrom }];
const usd: FxRate = { from: 'USD', to: 'EGP', rate: '50', date: '2026-01-01', sourceId: 'FX-FIXTURE', source: 'fixture', confidence: 'L2_supplier_tds', forDate: '2026-01-01' };

describe('offeredMaterials (S-05: quotes -> EGP prices for the optimizer)', () => {
  it('converts a USD quote with the dated rate: 2 USD x 50 = 100 EGP per kg, and keeps an EGP quote as it is', () => {
    const items: MaterialQuotes[] = [
      { materialId: 'AAA', quotes: [quote('2', 'USD')], activeMatter: active('50') },
      { materialId: 'WATER', quotes: [quote('1', 'EGP')], activeMatter: active('0') },
    ];
    const { materials, excluded } = offeredMaterials(items, [usd]);
    expect(excluded).toEqual([]);
    expect(materials.map((m) => [m.materialId, m.price?.value, m.price?.unit])).toEqual([['AAA', '100', 'EGP'], ['WATER', '1', 'EGP']]);
  });

  it('takes the best-confidence quote (L1 at 12 beats L5 at 9), the same rule as the cost report', () => {
    const items: MaterialQuotes[] = [{ materialId: 'AAA', quotes: [quote('9', 'EGP', 'L5_reference', '2026-02-01'), quote('12', 'EGP', 'L1_coa_batch')], activeMatter: active('50') }];
    const { materials } = offeredMaterials(items, []);
    expect(materials[0]?.price).toMatchObject({ value: '12', confidence: 'L1_coa_batch' });
  });

  it('lists a material without a usable price or active matter instead of offering it with a zero', () => {
    const items: MaterialQuotes[] = [
      { materialId: 'NOQUOTE', quotes: [], activeMatter: active('10') },
      { materialId: 'NORATE', quotes: [quote('2', 'USD')], activeMatter: active('10') },
      { materialId: 'NOACTIVE', quotes: [quote('5', 'EGP')] },
      { materialId: 'ESTIMATE', quotes: [quote('5', 'EGP', 'L7_estimate')], activeMatter: active('10') },
    ];
    const { materials, excluded } = offeredMaterials(items, []);
    expect(materials).toEqual([]);
    expect(excluded).toEqual([
      { materialId: 'ESTIMATE', property: 'price', reason: 'confidence.l7_requires_signed_override' },
      { materialId: 'NOACTIVE', property: 'activeMatterPct', reason: 'no_candidates' },
      { materialId: 'NOQUOTE', property: 'price', reason: 'no_candidates' },
      { materialId: 'NORATE', property: 'price', reason: 'cost.fx_rate_required' },
    ]);
  });

  it('feeds the optimizer: A at 2 USD (100 EGP, 50 % active) and water, active >= 10 % -> 20 % A, 80 % water, cost 20.8', async () => {
    const items: MaterialQuotes[] = [
      { materialId: 'AAA', quotes: [quote('2', 'USD')], activeMatter: active('50') },
      { materialId: 'WATER', quotes: [quote('1', 'EGP')], activeMatter: active('0') },
    ];
    const { materials } = offeredMaterials(items, [usd]);
    const out = await optimize({ profile: profile({ totalActiveMinPct: '10' }), materials, qsMaterialId: 'WATER' });
    expect(out.status).toBe('optimal');
    expect(out.costPerKg).toBe('20.8');
    expect(out.formula.map((l) => [l.materialId, l.percentWW])).toEqual([['WATER', '80.000'], ['AAA', '20.000']]);
  });
});

describe('withProfileSource (the one source of a saved profile)', () => {
  it('every field that holds a value cites the source; empty fields do not', () => {
    const p = withProfileSource(profile({ totalActiveMinPct: '10', maxCostEGPperKg: '30', forbiddenMaterials: ['X'] }), 'SRC-1');
    expect(p.sources).toEqual({ totalActiveMinPct: 'SRC-1', maxCostEGPperKg: 'SRC-1', forbiddenMaterials: 'SRC-1' });
  });

  it('a target retail price and a bounded material are sourced too (4 fields -> 4 entries)', () => {
    const p = withProfileSource(profile({ totalActiveMinPct: '10', retailPrice: '50', materialBounds: [{ materialId: 'AAA', min: '1', max: null }] }), 'SRC-1');
    expect(Object.keys(p.sources).sort()).toEqual(['materialBounds.AAA', 'targetRetailPrice', 'totalActiveMinPct']);
  });

  it('with no source the map is empty and the optimizer says "invalid: a value without a source" instead of solving', async () => {
    const p = withProfileSource(profile({ totalActiveMinPct: '10' }), null);
    expect(p.sources).toEqual({});
    const out = await optimize({ profile: p, materials: [], qsMaterialId: null });
    expect(out.status).toBe('invalid');
    expect(out.errors[0]?.key).toBe('optimizer.profile.source_required');
  });
});
