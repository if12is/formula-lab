import { describe, expect, it } from 'vitest';
import { MOLAR_MASS, MOLAR_MASS_SOURCES, naohPerKoh } from './molar-mass';

describe('molar mass constants', () => {
  it('holds the two molar masses as decimal strings with a source', () => {
    expect(MOLAR_MASS).toEqual({ NaOH: '39.997', KOH: '56.106' });
    expect(MOLAR_MASS_SOURCES.values).toBe(MOLAR_MASS);
    expect(MOLAR_MASS_SOURCES.sourceId).toMatch(/IUPAC/);
  });

  it('M_NaOH / M_KOH = 39.997 / 56.106 = 0.71287...', () => {
    // hand calc: 39.997 / 56.106 = 0.712883...
    expect(naohPerKoh().toDecimalPlaces(6).toFixed()).toBe('0.712883');
  });
});
