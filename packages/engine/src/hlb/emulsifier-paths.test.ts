import { describe, expect, it } from 'vitest';
import type { SourcedScalar } from '../core/parse';
import { anionicActiveMatter, cationicTdsDose, emulsifierPathFor } from './emulsifier-paths';

const sv = (value: string, confidence: SourcedScalar['confidence'] = 'L2_supplier_tds'): SourcedScalar => ({
  value,
  sourceId: 'test:fictional',
  confidence,
  validFrom: '2026-01-01',
});

describe('emulsifierPathFor: ionicity picks the path, and an undeclared one is missing', () => {
  it.each([
    ['nonionic', 'hlb'],
    ['cationic', 'cationic_tds'],
    ['anionic', 'anionic_active'],
    ['amphoteric', 'custom'],
    ['other', 'custom'],
  ] as const)('%s -> %s', (ionicity, path) => {
    const r = emulsifierPathFor('m', ionicity);
    expect(r.ok && r.value.path).toBe(path);
  });

  it('never assumes non-ionic', () => {
    const r = emulsifierPathFor('m', undefined);
    expect(!r.ok && r.missing).toEqual([{ materialId: 'm', property: 'ionicity', reason: 'ionicity_missing' }]);
  });
});

describe('cationicTdsDose: dose against the supplier TDS range (fictional numbers)', () => {
  const base = { materialId: 'cat', ionicity: 'cationic' as const, tdsMin: sv('2'), tdsMax: sv('5') };

  it('within the range: 3.5 in 2..5, gap 0', () => {
    const r = cationicTdsDose({ ...base, dosePercent: '3.5' });
    expect(r.ok && r.value).toEqual({ dosePercent: '3.5', min: '2', max: '5', position: 'within', gap: '0' });
  });

  it('above the range: 6.25 - 5 = 1.25', () => {
    const r = cationicTdsDose({ ...base, dosePercent: '6.25' });
    expect(r.ok && [r.value.position, r.value.gap]).toEqual(['above', '1.25']);
  });

  it('below the range: 2 - 0.75 = 1.25, and the ends count as within', () => {
    const below = cationicTdsDose({ ...base, dosePercent: '0.75' });
    expect(below.ok && [below.value.position, below.value.gap]).toEqual(['below', '1.25']);
    const edge = cationicTdsDose({ ...base, dosePercent: '5' });
    expect(edge.ok && edge.value.position).toBe('within');
  });

  it('a missing range is missing, never a default; both ends are listed', () => {
    const r = cationicTdsDose({ materialId: 'cat', ionicity: 'cationic', dosePercent: '3', tdsMin: undefined, tdsMax: undefined });
    expect(!r.ok && r.missing.map((m) => `${m.property}:${m.reason}`)).toEqual(['tdsMin:missing_input', 'tdsMax:missing_input']);
  });

  it('an L7 range is refused in the final calculation and allowed by the policy', () => {
    const input = { ...base, tdsMin: sv('2', 'L7_estimate'), dosePercent: '3' };
    const r = cationicTdsDose(input);
    expect(!r.ok && r.missing).toEqual([{ materialId: 'cat', property: 'tdsMin', reason: 'confidence_L7_rejected_by_policy' }]);
    expect(cationicTdsDose(input, { allowL7: true }).ok).toBe(true);
  });

  it('HLB does not apply: a non-ionic or undeclared material is refused here, and an inverted range is refused', () => {
    const nonionic = cationicTdsDose({ ...base, ionicity: 'nonionic', dosePercent: '3' });
    expect(!nonionic.ok && nonionic.missing[0]?.reason).toBe('path_cationic_only');
    const inverted = cationicTdsDose({ ...base, tdsMin: sv('5'), tdsMax: sv('2'), dosePercent: '3' });
    expect(!inverted.ok && inverted.missing[0]?.reason).toBe('tds_range_inverted');
  });
});

describe('anionicActiveMatter: mass x active % / 100 (fictional numbers)', () => {
  const base = { materialId: 'an', ionicity: 'anionic' as const };

  it('250 g at 70% active = 175 g', () => {
    const r = anionicActiveMatter({ ...base, mass: '250', activeMatter: sv('70') });
    expect(r.ok && r.value).toEqual({ activeMass: '175', activePercentOfTotal: null });
  });

  it('with the formula total: 120 g at 28% in 1000 g = 33.6 g = 3.36%', () => {
    const r = anionicActiveMatter({ ...base, mass: '120', activeMatter: sv('28'), totalMass: '1000' });
    expect(r.ok && r.value).toEqual({ activeMass: '33.6', activePercentOfTotal: '3.36' });
  });

  it('exact decimals: 1 g at 33.33% = 0.3333 g', () => {
    const r = anionicActiveMatter({ ...base, mass: '1', activeMatter: sv('33.33') });
    expect(r.ok && r.value.activeMass).toBe('0.3333');
  });

  it('missing, L7 and impossible inputs are listed, never zero', () => {
    const none = anionicActiveMatter({ ...base, mass: '10', activeMatter: undefined });
    expect(!none.ok && none.missing).toEqual([{ materialId: 'an', property: 'activeMatter', reason: 'missing_input' }]);
    const l7 = anionicActiveMatter({ ...base, mass: '10', activeMatter: sv('70', 'L7_estimate') });
    expect(!l7.ok && l7.missing[0]?.reason).toBe('confidence_L7_rejected_by_policy');
    const over = anionicActiveMatter({ ...base, mass: '10', activeMatter: sv('101') });
    expect(!over.ok && over.missing[0]?.reason).toBe('active_matter_over_100');
    const tooSmall = anionicActiveMatter({ ...base, mass: '10', activeMatter: sv('70'), totalMass: '5' });
    expect(!tooSmall.ok && tooSmall.missing[0]?.reason).toBe('invalid_total_mass');
  });

  it('HLB does not apply: a cationic material is refused here', () => {
    const r = anionicActiveMatter({ materialId: 'c', ionicity: 'cationic', mass: '10', activeMatter: sv('70') });
    expect(!r.ok && r.missing[0]?.reason).toBe('path_anionic_only');
  });
});
