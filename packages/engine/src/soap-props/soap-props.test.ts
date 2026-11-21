import { describe, expect, it } from 'vitest';
import type { SourcedScalar } from '../core/parse';
import { soapIndicators, type PropertyMap, type SoapOil } from './soap-props';

const sv = (value: string, sourceId = 'fict:oil', confidence: SourcedScalar['confidence'] = 'L5_reference'): SourcedScalar => ({
  value,
  sourceId,
  confidence,
  validFrom: '2026-01-01',
});

// EXPLICITLY FICTIONAL map and acids ("acidX" ...), not a real convention.
const fictionalMap: PropertyMap = {
  sourceId: 'fict:map',
  confidence: 'L5_reference',
  properties: {
    scoreA: { terms: [{ acid: 'acidX', coef: '1' }, { acid: 'acidY', coef: '1' }] },
    scoreB: { terms: [{ acid: 'acidZ', coef: '0.5' }] },
  },
};
const oil1: SoapOil = { oilId: 'o1', mass: '60', fattyAcids: { acidX: sv('10', 'src1'), acidY: sv('20', 'src1'), acidZ: sv('40', 'src1') } };
const oil2: SoapOil = { oilId: 'o2', mass: '40', fattyAcids: { acidX: sv('30', 'src2', 'L2_supplier_tds'), acidY: sv('0', 'src2'), acidZ: sv('70', 'src2', 'L6_community') } };

describe('soapIndicators', () => {
  it('mass-weighted blend then map terms', () => {
    // acidX = (60*10 + 40*30)/100 = 18 ; acidY = (60*20 + 0)/100 = 12 ; acidZ = (60*40 + 40*70)/100 = 52
    // scoreA = 18 + 12 = 30 ; scoreB = 0.5 * 52 = 26
    const r = soapIndicators([oil1, oil2], fictionalMap);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.blendedProfile).toEqual({ acidX: '18', acidY: '12', acidZ: '52' });
      expect(r.value.indicators.scoreA).toMatchObject({ kind: 'indicator', value: '30', mapSourceId: 'fict:map' });
      expect(r.value.indicators.scoreB?.value).toBe('26');
      expect(r.value.indicators.scoreA?.sourceIds).toEqual(['fict:map', 'src1', 'src2']);
      expect(r.value.indicators.scoreA?.confidence).toBe('L6_community'); // worst used
    }
  });
  it('every output is tagged indicator', () => {
    const r = soapIndicators([oil1], fictionalMap);
    expect(r.ok && Object.values(r.value.indicators).every((i) => i.kind === 'indicator')).toBe(true);
  });
  it('no map => missing (no default map shipped)', () => {
    const r = soapIndicators([oil1], undefined);
    expect(!r.ok && r.missing[0]?.property).toBe('propertyMap');
  });
  it('invalid maps are reported', () => {
    const bad: PropertyMap = {
      sourceId: '',
      confidence: 'L5_reference',
      properties: { empty: { terms: [] }, broken: { terms: [{ acid: 'acidX', coef: 'abc' }] } },
    };
    const r = soapIndicators([oil1], bad);
    expect(!r.ok && r.missing.map((m) => m.reason)).toEqual([
      'missing_sourceId',
      'no_terms',
      'invalid_term: acid=acidX coef=abc',
    ]);
    const none = soapIndicators([oil1], { sourceId: 'x', confidence: 'L5_reference', properties: {} });
    expect(!none.ok && none.missing[0]?.reason).toBe('no_properties_defined');
  });
  it('missing acid for an oil lists every oil/acid gap', () => {
    const lacking: SoapOil = { oilId: 'o3', mass: '10', fattyAcids: { acidX: sv('1') } };
    const r = soapIndicators([lacking, oil1], fictionalMap);
    expect(!r.ok && r.missing.map((m) => `${m.materialId}:${m.property}`)).toEqual(['o3:fattyAcid:acidY', 'o3:fattyAcid:acidZ']);
  });
  it('invalid acid %, invalid mass, profile sum above 100', () => {
    const r = soapIndicators(
      [
        { ...oil1, oilId: 'a', fattyAcids: { ...oil1.fattyAcids, acidX: sv('abc') } },
        { ...oil1, oilId: 'b', mass: '-1' },
        { ...oil1, oilId: 'c', fattyAcids: { acidX: sv('60'), acidY: sv('50'), acidZ: sv('1') } },
        { ...oil1, oilId: 'd', fattyAcids: { ...oil1.fattyAcids, acidX: sv('101'), acidY: sv('0'), acidZ: sv('0') } },
      ],
      fictionalMap,
    );
    expect(r.ok).toBe(false);
    if (!r.ok) {
      const byOil = Object.fromEntries(r.missing.map((m) => [m.materialId, m.reason]));
      expect(byOil.a).toBe('invalid_fatty_acid_percent');
      expect(byOil.b).toBe('invalid_mass');
      expect(byOil.c).toContain('profile_sums_above_100');
      expect(byOil.d).toBeDefined();
    }
  });
  it('no oils / zero total mass => missing', () => {
    expect(soapIndicators([], fictionalMap).ok).toBe(false);
    const z = soapIndicators([{ ...oil1, mass: '0' }], fictionalMap);
    expect(!z.ok && z.missing[0]?.reason).toBe('total_oil_mass_zero');
  });
  it('only acids referenced by the map are required', () => {
    const lean: SoapOil = { oilId: 'o', mass: '1', fattyAcids: { acidZ: sv('10') } };
    const r = soapIndicators([lean], { sourceId: 'm', confidence: 'L5_reference', properties: { onlyZ: { terms: [{ acid: 'acidZ', coef: '2' }] } } });
    expect(r.ok && r.value.indicators.onlyZ?.value).toBe('20');
  });
});
