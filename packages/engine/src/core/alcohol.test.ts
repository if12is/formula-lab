import { describe, expect, it } from 'vitest';
import { Decimal } from 'decimal.js';
import tableJson from '../../data/oiml-r22/table-20c.json';
import {
  SOURCE, densityFromWW, wwFromDensity, vvFromWW, wwFromVV, densityFromVV, vvFromDensity,
  type OimlTable,
} from './alcohol';

// Synthetic NON-PHYSICAL fixture: exercises the interpolation engine only.
// Values are deliberately fake (linear, density decreasing) and are not OIML data.
const FAKE: OimlTable = {
  rows: [
    { pctWW: '0', pctVV: '0', densityKgM3: '1000' },
    { pctWW: '50', pctVV: '60', densityKgM3: '900' },
    { pctWW: '100', pctVV: '100', densityKgM3: '800' },
  ],
};

describe('alcohol: shipped table', () => {
  it('SOURCE records official retrieval', () => {
    expect(SOURCE.retrievedOn).toBe('2026-11-21');
    expect(SOURCE.confidence).toBe('L3_standard');
  });
  it('empty table gives missing oiml_r22_table', () => {
    for (const fn of [densityFromWW, vvFromWW, wwFromVV, densityFromVV, wwFromDensity, vvFromDensity]) {
      const r = fn('40', { rows: [] });
      expect(r.ok).toBe(false);
      if (!r.ok) expect(r.missing[0]!.property).toBe('oiml_r22_table');
    }
  });
  it('shipped table is monotone in all columns', () => {
    const rows = (tableJson as OimlTable).rows;
    expect(rows).toHaveLength(1001);
    for (let i = 1; i < rows.length; i++) {
      expect(new Decimal(rows[i]!.densityKgM3).lt(rows[i - 1]!.densityKgM3)).toBe(true);
      expect(new Decimal(rows[i]!.pctVV).gt(rows[i - 1]!.pctVV)).toBe(true);
    }
  });
});

describe('alcohol: interpolation engine (fake fixture)', () => {
  it('exact row', () => {
    const r = densityFromWW('50', FAKE);
    expect(r.ok && r.value.toString()).toBe('900');
  });
  it('linear interpolation', () => {
    const r = densityFromWW('25', FAKE);
    expect(r.ok && r.value.toString()).toBe('950');
  });
  it('round trip ww -> density -> ww', () => {
    for (const w of ['3.7', '25', '77.123']) {
      const d = densityFromWW(w, FAKE);
      if (!d.ok) throw new Error('x');
      const back = wwFromDensity(d.value, FAKE);
      expect(back.ok && back.value.minus(w).abs().lt('1e-25')).toBe(true);
    }
  });
  it('monotone: density decreases with ww', () => {
    let prev = new Decimal(2000);
    for (let w = 0; w <= 100; w += 5) {
      const r = densityFromWW(String(w), FAKE);
      if (!r.ok) throw new Error('x');
      expect(r.value.lte(prev)).toBe(true);
      prev = r.value;
    }
  });
  it('v/v conversions', () => {
    const a = vvFromWW('25', FAKE);
    expect(a.ok && a.value.toString()).toBe('30');
    const b = wwFromVV('80', FAKE);
    expect(b.ok && b.value.toString()).toBe('75');
    expect(densityFromVV('30', FAKE).ok).toBe(true);
    expect(vvFromDensity('950', FAKE).ok).toBe(true);
  });
  it('never extrapolates', () => {
    for (const bad of ['-0.1', '100.1']) {
      const r = densityFromWW(bad, FAKE);
      expect(r.ok).toBe(false);
    }
    expect(wwFromDensity('799', FAKE).ok).toBe(false);
    expect(wwFromDensity('1001', FAKE).ok).toBe(false);
  });
  it('rejects JS numbers and junk', () => {
    const r = densityFromWW(25 as unknown, FAKE);
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.missing[0]!.reason).toMatch(/invalid_input/);
    expect(densityFromWW('abc', FAKE).ok).toBe(false);
  });
  it('accepts Decimal input from engine D and a foreign Decimal', () => {
    expect(densityFromWW(new Decimal('25'), FAKE).ok).toBe(true);
  });
});

// Acceptance: 50 points transcribed from the OFFICIAL printed OIML R 22 (1975) tables,
// https://www.oiml.org/en/files/pdf_r/r022-e75.pdf
//  - density at 20 C: Table IIIa, printed pp.44-45 (column 0.0, p = 0,2,...,98 %mass)
//  - %vol: Table IIIb, printed pp.48-49 (same rows)
// Format: [%mass, density kg/m3 (2 dp as printed), %vol (2 dp as printed)].
const OFFICIAL: [string, string, string][] = [
  ['0', '998.20', '0.00'],
  ['2', '994.49', '2.52'],
  ['4', '991.02', '5.02'],
  ['6', '987.78', '7.51'],
  ['8', '984.73', '9.98'],
  ['10', '981.85', '12.44'],
  ['12', '979.10', '14.89'],
  ['14', '976.44', '17.32'],
  ['16', '973.83', '19.74'],
  ['18', '971.24', '22.15'],
  ['20', '968.61', '24.55'],
  ['22', '965.90', '26.92'],
  ['24', '963.09', '29.29'],
  ['26', '960.14', '31.63'],
  ['28', '957.05', '33.95'],
  ['30', '953.78', '36.25'],
  ['32', '950.36', '38.53'],
  ['34', '946.77', '40.79'],
  ['36', '943.03', '43.01'],
  ['38', '939.15', '45.22'],
  ['40', '935.15', '47.39'],
  ['42', '931.03', '49.55'],
  ['44', '926.82', '51.67'],
  ['46', '922.53', '53.77'],
  ['48', '918.18', '55.84'],
  ['50', '913.77', '57.89'],
  ['52', '909.31', '59.91'],
  ['54', '904.81', '61.91'],
  ['56', '900.28', '63.88'],
  ['58', '895.70', '65.82'],
  ['60', '891.10', '67.74'],
  ['62', '886.46', '69.64'],
  ['64', '881.79', '71.51'],
  ['66', '877.09', '73.35'],
  ['68', '872.37', '75.16'],
  ['70', '867.61', '76.95'],
  ['72', '862.83', '78.71'],
  ['74', '858.02', '80.45'],
  ['76', '853.17', '82.16'],
  ['78', '848.30', '83.84'],
  ['80', '843.39', '85.49'],
  ['82', '838.43', '87.11'],
  ['84', '833.41', '88.70'],
  ['86', '828.32', '90.26'],
  ['88', '823.15', '91.78'],
  ['90', '817.88', '93.27'],
  ['92', '812.49', '94.71'],
  ['94', '806.97', '96.11'],
  ['96', '801.27', '97.46'],
  ['98', '795.38', '98.76'],
];
// Extra fractional points read from Table IIIa p.44-45: [%mass, density].
const OFFICIAL_FRACTIONAL: [string, string][] = [
  ['0.5', '997.25'],
  ['12.3', '978.69'],
  ['99.9', '789.55'],
];

describe('alcohol: official OIML R 22 table, 50 points', () => {
  it('has 50 reference points', () => expect(OFFICIAL).toHaveLength(50));
  it.each(OFFICIAL)('p=%s %%mass -> density %s, %%vol %s', (p, rho, q) => {
    const d = densityFromWW(p);
    expect(d.ok && d.value.toDecimalPlaces(2).toFixed(2)).toBe(rho);
    const v = vvFromWW(p);
    expect(v.ok && v.value.toDecimalPlaces(2).toFixed(2)).toBe(q);
    const w = wwFromVV(q);
    expect(w.ok && w.value.minus(p).abs().lt('0.01')).toBe(true);
  });
  it.each(OFFICIAL_FRACTIONAL)('p=%s %%mass -> density %s', (p, rho) => {
    const d = densityFromWW(p);
    expect(d.ok && d.value.toDecimalPlaces(2).toFixed(2)).toBe(rho);
  });
  it('pure ethanol at 20 C is 789.24 kg/m3 (p.5, p.45)', () => {
    const d = densityFromWW('100');
    expect(d.ok && d.value.toDecimalPlaces(2).toFixed(2)).toBe('789.24');
  });
});
