import { describe, expect, it } from 'vitest';
import { cl2PerNaocl, MOLAR_MASS, MOLAR_MASS_SOURCES, naohPerKoh } from './molar-mass';

describe('molar mass constants', () => {
  it('holds the two molar masses as decimal strings with a source', () => {
    expect(MOLAR_MASS).toEqual({ NaOH: '39.997', KOH: '56.106', Cl2: '70.90', NaOCl: '74.44' });
    expect(MOLAR_MASS_SOURCES.values).toBe(MOLAR_MASS);
    expect(MOLAR_MASS_SOURCES.sourceId).toMatch(/IUPAC/);
  });

  it('M_NaOH / M_KOH = 39.997 / 56.106 = 0.71287...', () => {
    // hand calc: 39.997 / 56.106 = 0.712883...
    expect(naohPerKoh().toDecimalPlaces(6).toFixed()).toBe('0.712883');
  });

  it('M_Cl2 / M_NaOCl = 70.90 / 74.44 = 0.95244...', () => {
    // hand calc: 74.44 x 0.95 = 70.718; remainder 70.90 - 70.718 = 0.182; 0.182 / 74.44 = 0.0024449...
    // so 70.90 / 74.44 = 0.9524449... -> 0.952445 at 6 dp
    expect(cl2PerNaocl().toDecimalPlaces(6).toFixed()).toBe('0.952445');
  });
});
