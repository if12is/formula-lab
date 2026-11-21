import { describe, expect, it } from 'vitest';
import { massToVolume } from '../core/convert';
import { qty } from '../core/quantity';
import type { SourcedValue } from '../contract';
import { preferMeasuredDensity } from './density';

const measured: SourcedValue = { value: '1.04', unit: 'g/mL', sourceId: 'lab_measurement:7', confidence: 'L1_coa_batch', validFrom: '2026-11-10' };
const estimated: SourcedValue = { value: '1', unit: 'kg/L', sourceId: 'material:water', confidence: 'L5_reference', validFrom: '2025-01-01' };

describe('preferMeasuredDensity', () => {
  it('measured wins over estimated; its source and confidence flow into the trace; 2.08 kg / 1.04 = 2 L', () => {
    const r = preferMeasuredDensity(measured, estimated);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.basis).toBe('measured');
      expect(r.value.ref.value).toBe('1.04');
      expect(r.value.leaf.trace.sourceId).toBe('lab_measurement:7');
      expect(r.value.leaf.trace.confidence).toBe('L1_coa_batch');
      const v = massToVolume(qty('2.08', 'kg'), r.value.ref, 'L');
      expect(v.ok && v.value.value.toFixed()).toBe('2');
      expect(r.warnings).not.toContain('density.estimated_used');
    }
  });

  it('only an estimate: it is used, marked estimated with a warning; 2 kg / 1 = 2 L', () => {
    const r = preferMeasuredDensity(undefined, estimated);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.basis).toBe('estimated');
      expect(r.value.leaf.trace.sourceId).toBe('material:water');
      expect(r.warnings).toContain('density.estimated_used');
      const v = massToVolume(qty('2', 'kg'), r.value.ref, 'L');
      expect(v.ok && v.value.value.toFixed()).toBe('2');
    }
  });

  it('neither => missing', () => {
    const r = preferMeasuredDensity(undefined, undefined, {}, 'F-1');
    expect(!r.ok && r.missing).toEqual([{ materialId: 'F-1', property: 'productDensity', reason: 'density.required_for_conversion' }]);
  });

  it('an unusable measured density is NOT replaced by the estimate', () => {
    const r = preferMeasuredDensity({ ...measured, value: '0' }, estimated);
    expect(!r.ok && r.missing[0]?.reason).toBe('density.must_be_positive_density_unit');
  });

  it('an L7 estimate still needs a signed override; a measured L1 does not', () => {
    const l7: SourcedValue = { ...estimated, confidence: 'L7_estimate' };
    const r = preferMeasuredDensity(undefined, l7);
    expect(!r.ok && r.missing[0]?.reason).toBe('confidence.l7_requires_signed_override');
    expect(preferMeasuredDensity(measured, l7).ok).toBe(true);
  });
});
