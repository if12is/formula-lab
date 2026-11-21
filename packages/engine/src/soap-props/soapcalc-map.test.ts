import { describe, expect, it } from 'vitest';
import mapJson from '../../data/soap-property-maps/soapcalc.json';
import { soapIndicators, type PropertyMap, type SoapOil } from './soap-props';

const map = mapJson as unknown as PropertyMap;
const acids = (p: Record<string, string>) => Object.fromEntries(Object.entries(p).map(([k, v]) => [k, { value: v, sourceId: 'OIL-SRC', confidence: 'L2_supplier_tds' as const, validFrom: '2026-01-01' }]));

describe('soapcalc property map (data, L6_community)', () => {
  it('is the community convention at L6 and does not map iodine or INS (those are not acid sums)', () => {
    expect(mapJson).toMatchObject({ sourceId: 'soapcalc-convention', confidence: 'L6_community', pendingVerification: true, notMapped: ['iodine', 'ins'] });
    expect(Object.keys(mapJson.properties).sort()).toEqual(['bubbly', 'cleansing', 'conditioning', 'creamy', 'hardness']);
  });

  it('hand-computed on two oils, mass weighted: 100 g and 300 g', () => {
    const a: SoapOil = { oilId: 'A', mass: '100', fattyAcids: acids({ lauric: '50', myristic: '20', palmitic: '10', stearic: '5', oleic: '10', linoleic: '3', linolenic: '1', ricinoleic: '1' }) };
    const b: SoapOil = { oilId: 'B', mass: '300', fattyAcids: acids({ lauric: '0', myristic: '0', palmitic: '10', stearic: '10', oleic: '70', linoleic: '10', linolenic: '0', ricinoleic: '0' }) };
    // blended: lauric 12.5, myristic 5, palmitic 10, stearic 8.75, oleic 55, linoleic 8.25, linolenic 0.25, ricinoleic 0.25
    const r = soapIndicators([a, b], map);
    expect(r.ok && Object.fromEntries(Object.entries(r.value.indicators).map(([k, v]) => [k, v.value]))).toEqual({
      hardness: '36.25', // 12.5 + 5 + 10 + 8.75
      cleansing: '17.5', // 12.5 + 5
      conditioning: '63.75', // 55 + 0.25 + 8.25 + 0.25
      bubbly: '17.75', // 12.5 + 5 + 0.25
      creamy: '19', // 10 + 8.75 + 0.25
    });
    expect(r.ok && r.value.indicators.hardness).toMatchObject({ kind: 'indicator', mapSourceId: 'soapcalc-convention', confidence: 'L6_community' });
  });

  it('a missing fatty acid is reported, never read as 0', () => {
    const r = soapIndicators([{ oilId: 'A', mass: '100', fattyAcids: acids({ lauric: '50' }) }], map);
    expect(!r.ok && r.missing.some((m) => m.property === 'fattyAcid:stearic' && m.materialId === 'A')).toBe(true);
  });
});
