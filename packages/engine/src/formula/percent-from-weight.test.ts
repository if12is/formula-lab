import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import { qty } from '../core/quantity';
import { percentFromWeight } from './percent-from-weight';

const rho = (value: string, id = 'LAB-1'): SourcedValue => ({ value, unit: 'kg/L', sourceId: id, confidence: 'L1_coa_batch', validFrom: '2026-01-01' });

describe('percentFromWeight', () => {
  it('g and kg: 250 g in 5 kg = 5 %w/w', () => {
    const r = percentFromWeight({ amount: qty('250', 'g'), batchSize: qty('5', 'kg') });
    expect(r.ok && r.value.value).toBe('5');
    expect(r.ok && r.value.unit).toBe('%w/w');
  });

  it('mL material needs its density: 100 mL at 0.9 in 2 kg = 4.5 %', () => {
    const r = percentFromWeight({ materialId: 'OIL', amount: qty('100', 'mL'), batchSize: qty('2', 'kg'), materialDensity: rho('0.9', 'TDS-OIL') });
    expect(r.ok && r.value.value).toBe('4.5');
    expect(r.ok && r.value.trace.inputs[0]?.inputs.map((i) => i.sourceId)).toEqual([undefined, 'TDS-OIL']);
  });

  it('missing material density => missing with materialId, never a default', () => {
    const r = percentFromWeight({ materialId: 'OIL', amount: qty('100', 'mL'), batchSize: qty('2', 'kg') });
    expect(!r.ok && r.missing).toEqual([{ materialId: 'OIL', property: 'density', reason: 'density.required_for_conversion' }]);
  });

  it('volume batch needs the measured product density; both missing are listed', () => {
    const r = percentFromWeight({ materialId: 'OIL', amount: qty('1', 'L'), batchSize: qty('10', 'L') });
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['density', 'productDensity']);
    const ok = percentFromWeight({ amount: qty('90', 'g'), batchSize: qty('10', 'L'), productDensity: rho('1.2') });
    expect(ok.ok && ok.value.value).toBe('0.75');
  });

  it('warns above 100 %, accepts zero, rejects negative amounts and non-positive batches', () => {
    const over = percentFromWeight({ amount: qty('2', 'kg'), batchSize: qty('1', 'kg') });
    expect(over.ok && over.value.value).toBe('200');
    expect(over.warnings).toEqual(['percent_from_weight.exceeds_100']);
    expect(percentFromWeight({ amount: qty('0', 'g'), batchSize: qty('1', 'kg') }).ok).toBe(true);
    expect(percentFromWeight({ amount: qty('-1', 'g'), batchSize: qty('1', 'kg') }).ok).toBe(false);
    expect(percentFromWeight({ amount: qty('1', 'g'), batchSize: qty('0', 'kg') }).ok).toBe(false);
  });

  it('rejects a non mass/volume amount as missing', () => {
    const r = percentFromWeight({ amount: qty('1', 'EGP'), batchSize: qty('1', 'kg') });
    expect(r.ok).toBe(false);
  });

  it('passes density confidence warnings through', () => {
    const r = percentFromWeight({
      amount: qty('1', 'mL'), batchSize: qty('1', 'kg'),
      materialDensity: { ...rho('1'), confidence: 'L5_reference' }, policy: { allowConfidenceBelow: 'L2_supplier_tds' },
    });
    expect(r.warnings).toEqual(['confidence.below_policy:-:density:L5_reference']);
  });
});
