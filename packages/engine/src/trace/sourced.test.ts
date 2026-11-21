import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import { gateConfidence, isWorseConfidence, resolveDensity } from './sourced';

const base: SourcedValue = { value: '10', unit: 'EGP', sourceId: 'S', confidence: 'L2_supplier_tds', validFrom: '2026-01-01' };

describe('gateConfidence', () => {
  it('passes good values silently', () => {
    const r = gateConfidence('A', 'price', base, {});
    expect(r.ok && r.warnings).toEqual([]);
  });

  it('warns below the policy floor, honours the default floor L6', () => {
    const l5 = { ...base, confidence: 'L5_reference' as const };
    expect(gateConfidence('A', 'price', l5, {}).warnings).toEqual([]);
    expect(gateConfidence('A', 'price', l5, { allowConfidenceBelow: 'L3_standard' }).warnings).toEqual([
      'confidence.below_policy:A:price:L5_reference',
    ]);
    expect(isWorseConfidence('L6_community', 'L3_standard')).toBe(true);
    expect(isWorseConfidence('L3_standard', 'L3_standard')).toBe(false);
  });

  it('L7 is missing unless a complete signed override matches material and property', () => {
    const l7 = { ...base, confidence: 'L7_estimate' as const };
    const r = gateConfidence('A', 'price', l7, {});
    expect(!r.ok && r.missing).toEqual([{ materialId: 'A', property: 'price', reason: 'confidence.l7_requires_signed_override' }]);
    const good = { materialId: 'A', property: 'price', signedBy: 'chem-1', reason: 'quote pending' };
    const okR = gateConfidence('A', 'price', l7, { overrides: [good] });
    expect(okR.ok && okR.warnings).toEqual(['confidence.l7_override:A:price']);
    for (const bad of [{ ...good, signedBy: ' ' }, { ...good, reason: '' }, { ...good, materialId: 'B' }, { ...good, property: 'density' }]) {
      expect(gateConfidence('A', 'price', l7, { overrides: [bad] }).ok).toBe(false);
    }
  });

  it('product-level override has no materialId', () => {
    const l7 = { ...base, confidence: 'L7_estimate' as const };
    const o = { property: 'productDensity', signedBy: 's', reason: 'r' };
    expect(gateConfidence(undefined, 'productDensity', l7, { overrides: [o] }).ok).toBe(true);
  });
});

describe('resolveDensity', () => {
  const d: SourcedValue = { value: '1.02', unit: 'kg/L', sourceId: 'LAB-1', confidence: 'L1_coa_batch', validFrom: '2026-01-01' };
  it('returns ref and leaf with provenance', () => {
    const r = resolveDensity(undefined, 'productDensity', d, {}, 'rho');
    expect(r.ok && r.value.ref).toMatchObject({ value: '1.02', unit: 'kg/L', sourceId: 'LAB-1' });
    expect(r.ok && r.value.leaf.trace.sourceId).toBe('LAB-1');
  });
  it('missing, wrong unit, non-positive and L7 are all missing', () => {
    expect(resolveDensity(undefined, 'productDensity', undefined, {}, 'r').ok).toBe(false);
    expect(resolveDensity(undefined, 'productDensity', { ...d, unit: 'kg' }, {}, 'r').ok).toBe(false);
    expect(resolveDensity(undefined, 'productDensity', { ...d, value: '0' }, {}, 'r').ok).toBe(false);
    expect(resolveDensity(undefined, 'productDensity', { ...d, confidence: 'L7_estimate' }, {}, 'r').ok).toBe(false);
  });
});
