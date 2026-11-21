import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import { qty } from '../core/quantity';
import { planBatch } from './plan';
import { yieldFraction } from './wastage';

const rho = (value: string, extra: Partial<SourcedValue> = {}): SourcedValue => ({
  value, unit: 'kg/L', sourceId: 'LAB-RHO', confidence: 'L1_coa_batch', validFrom: '2026-01-01', ...extra,
});
const v = (o: ReturnType<typeof planBatch>, k: string) => o.results[k]?.value;

describe('yieldFraction', () => {
  it('1 - w/100 inside [0,100)', () => {
    const r = yieldFraction('20');
    expect(r.ok && r.value.toFixed()).toBe('0.8');
  });
  it('rejects 100, negatives and garbage', () => {
    for (const w of ['100', '-1', '150', 'abc']) {
      expect(yieldFraction(w).ok).toBe(false);
    }
  });
});

describe('planBatch', () => {
  it('floor + separate remainder, L batch with measured density', () => {
    const o = planBatch({ size: qty('10', 'L'), wastagePct: '0', packSize: qty('300', 'mL'), productDensity: rho('1.00') });
    expect(o.missing).toEqual([]);
    expect(v(o, 'batch.fullPacks')).toBe('33');
    expect(v(o, 'batch.remainder')).toBe('100');
    expect(o.results['batch.remainder']?.unit).toBe('mL');
    expect(o.results['batch.fullPacks']?.unit).toBe('pc');
    expect(v(o, 'batch.netMass')).toBe('10');
  });

  it('never rounds up even when the remainder is almost a pack', () => {
    const o = planBatch({ size: qty('999', 'mL'), wastagePct: '0', packSize: qty('500', 'mL'), productDensity: rho('1') });
    expect(v(o, 'batch.fullPacks')).toBe('1');
    expect(v(o, 'batch.remainder')).toBe('499');
  });

  it('a batch smaller than one pack yields 0 packs and the whole batch as remainder', () => {
    const o = planBatch({ size: qty('200', 'mL'), wastagePct: '0', packSize: qty('500', 'mL'), productDensity: rho('1') });
    expect(v(o, 'batch.fullPacks')).toBe('0');
    expect(v(o, 'batch.remainder')).toBe('200');
  });

  it('kg batch and gram pack need no density for packs, but report productDensity missing', () => {
    const o = planBatch({ size: qty('40', 'kg'), wastagePct: '25', packSize: qty('250', 'g') });
    expect(v(o, 'batch.fullPacks')).toBe('160');
    expect(v(o, 'batch.remainder')).toBe('0');
    expect(v(o, 'batch.grossMass')).toBe('53.33333333333333333333333333333333');
    expect(o.missing).toEqual([{ property: 'productDensity', reason: 'density.required_for_conversion' }]);
    expect(o.results['batch.netVolume']).toBeUndefined();
    expect(o.results['batch.packMass']?.value).toBe('0.25');
  });

  it('mass batch with volume pack and no density: kg results only, no packs', () => {
    const o = planBatch({ size: qty('40', 'kg'), wastagePct: '0', packSize: qty('500', 'mL') });
    expect(Object.keys(o.results).sort()).toEqual(['batch.grossMass', 'batch.netMass']);
    expect(o.missing.map((m) => m.property)).toEqual(['productDensity']);
  });

  it('volume batch without density: no mass, still volume and gross volume', () => {
    const o = planBatch({ size: qty('20', 'L'), wastagePct: '50', packSize: qty('1', 'L') });
    expect(v(o, 'batch.netMass')).toBeUndefined();
    expect(v(o, 'batch.grossVolume')).toBe('40');
    expect(v(o, 'batch.fullPacks')).toBe('20');
  });

  it('density converts both ways and traces the density leaf', () => {
    const o = planBatch({ size: qty('51', 'kg'), wastagePct: '0', packSize: qty('500', 'mL'), productDensity: rho('1.02') });
    expect(v(o, 'batch.netVolume')).toBe('50');
    expect(v(o, 'batch.fullPacks')).toBe('100');
    expect(v(o, 'batch.packMass')).toBe('0.51');
    const leaf = o.results['batch.netVolume']?.trace.inputs[1];
    expect(leaf).toMatchObject({ sourceId: 'LAB-RHO', confidence: 'L1_coa_batch' });
  });

  it('pack units g/kg/mL/L are normalised', () => {
    const o = planBatch({ size: qty('7.5', 'kg'), wastagePct: '0', packSize: qty('1', 'kg'), productDensity: rho('1') });
    expect(v(o, 'batch.fullPacks')).toBe('7');
    expect(v(o, 'batch.remainder')).toBe('500');
    expect(o.results['batch.remainder']?.unit).toBe('g');
  });

  it('invalid wastage is reported but the rest is computed', () => {
    const o = planBatch({ size: qty('1', 'kg'), wastagePct: '100', productDensity: rho('1') });
    expect(o.missing).toEqual([{ property: 'wastagePct', reason: 'batch.wastage_out_of_range' }]);
    expect(v(o, 'batch.netMass')).toBe('1');
    expect(v(o, 'batch.grossMass')).toBeUndefined();
  });

  it('rejects bad sizes', () => {
    expect(planBatch({ size: qty('0', 'kg'), wastagePct: '0' }).missing[0]?.property).toBe('batchSize');
    expect(planBatch({ size: qty('1', 'EGP'), wastagePct: '0' }).missing[0]?.reason).toBe('batch.unit_must_be_mass_or_volume');
    expect(planBatch({ size: qty('1', 'kg'), wastagePct: '0', packSize: qty('0', 'g') }).missing[0]?.property).toBe('packSize');
  });

  it('density L7 without override is missing; warnings flow', () => {
    const o = planBatch({ size: qty('1', 'L'), wastagePct: '0', productDensity: rho('1', { confidence: 'L7_estimate' }) });
    expect(o.missing[0]).toMatchObject({ property: 'productDensity', reason: 'confidence.l7_requires_signed_override' });
    const w = planBatch({
      size: qty('1', 'L'), wastagePct: '0', productDensity: rho('1', { confidence: 'L5_reference' }),
      policy: { allowConfidenceBelow: 'L2_supplier_tds' },
    });
    expect(w.warnings).toEqual(['confidence.below_policy:-:productDensity:L5_reference']);
  });
});
