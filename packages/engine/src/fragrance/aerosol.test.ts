import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { aerosol, AEROSOL_REASONS, AEROSOL_WARNINGS, type AerosolInput } from './aerosol';

/* Ratios, the can's rated maximum and IFRA limits are TEST INPUTS (sourceId TEST-FIXTURE): the engine has no built-in value. */
const base = (over: Partial<AerosolInput> = {}): AerosolInput => ({
  fragranceId: 'FRAG-1',
  propellantRatio: fixture('4', 'pc'),
  ratioBasis: 'tds',
  fragranceOilPctOfConcentrate: '5',
  netFillMass: qty('150', 'g'),
  policy: {},
  ...over,
});
const run = (over: Partial<AerosolInput> = {}) => {
  const r = aerosol(base(over));
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r;
};
const gaps = (over: Partial<AerosolInput>) => {
  const r = aerosol(base(over));
  return r.ok ? null : r.missing.map((m) => [m.property, m.reason]);
};

describe('aerosol (L-06)', () => {
  it('hand case 1: 20:80 (r = 4), 150 g can, 5 % oil in the concentrate: 30 g concentrate, 120 g gas, 1.5 g oil, 1 % oil in the product', () => {
    const { value: a } = run();
    expect([a.concentrateSharePct.value, a.propellantSharePct.value]).toEqual(['20', '80']);
    expect([a.concentrateMassPerCan.value, a.propellantMassPerCan.value, a.oilMassPerCan.value, a.oilPctOfProduct.value]).toEqual(['30', '120', '1.5', '1']);
    expect(a.batch).toBeUndefined();
  });

  it('hand case 2: 25:75 (r = 3), 200 g can, 10 % oil, 1000 cans: 50 g + 150 g per can, 5 g oil, 2.5 % in the product; 50 kg concentrate and 150 kg gas for the run', () => {
    const { value: a } = run({ propellantRatio: fixture('3', 'pc'), netFillMass: qty('200', 'g'), fragranceOilPctOfConcentrate: '10', cans: '1000' });
    expect([a.concentrateMassPerCan.value, a.propellantMassPerCan.value, a.oilMassPerCan.value, a.oilPctOfProduct.value]).toEqual(['50', '150', '5', '2.5']);
    expect([a.batch?.concentrateMass.value, a.batch?.propellantMass.value]).toEqual(['50000', '150000']);
  });

  it('hand case 3: 40:60 (r = 1.5), 0.1 kg can, 2.5 % oil: 40 g concentrate, 60 g gas, 1 g oil, 1 % in the product', () => {
    const { value: a } = run({ propellantRatio: fixture('1.5', 'pc'), netFillMass: qty('0.1', 'kg'), fragranceOilPctOfConcentrate: '2.5' });
    expect([a.concentrateSharePct.value, a.propellantSharePct.value]).toEqual(['40', '60']);
    expect([a.concentrateMassPerCan.value, a.propellantMassPerCan.value, a.oilMassPerCan.value, a.oilPctOfProduct.value]).toEqual(['40', '60', '1', '1']);
  });

  it('always warns that an aerosol needs a pressurised filling line and safety conditions (the one warning the research states)', () => {
    expect(run().warnings).toContain(AEROSOL_WARNINGS.FILLING_LINE);
  });

  it('the fill check needs a sourced rated maximum: 140 g FAIL by 10 g, 150 g PASS, none UNKNOWN with a warning', () => {
    const fail = run({ maxFillMassG: fixture('140', 'g') }).value.fillCheck;
    expect([fail.status, fail.margin?.value]).toEqual(['FAIL', '-10']);
    expect(run({ maxFillMassG: fixture('150', 'g') }).value.fillCheck.status).toBe('PASS');
    const unknown = run();
    expect(unknown.value.fillCheck.status).toBe('UNKNOWN');
    expect(unknown.warnings).toContain(AEROSOL_WARNINGS.FILL_LIMIT_UNKNOWN);
  });

  it('a ratio without a source, a missing ratio or basis, or a bad can count is reported, never defaulted', () => {
    expect(gaps({ propellantRatio: undefined })).toEqual([['propellantRatio', 'input.missing']]);
    expect(gaps({ ratioBasis: undefined })).toEqual([['ratioBasis', AEROSOL_REASONS.RATIO_BASIS_REQUIRED]]);
    expect(gaps({ propellantRatio: { ...fixture('4', 'pc'), validFrom: 'x' } })).toEqual([['propellantRatio', 'input.not_dated']]);
    expect(gaps({ cans: '2.5' })).toEqual([['cans', AEROSOL_REASONS.CANS_INVALID]]);
  });

  it('the IFRA hook checks the oil % of the filled product: 1 % against 0.8 FAIL, against 1 PASS, no limit UNKNOWN', () => {
    expect(run({ ifraLimitPct: fixture('0.8', '%w/w') }).value.ifra.status).toBe('FAIL');
    expect(run({ ifraLimitPct: fixture('1', '%w/w') }).value.ifra.status).toBe('PASS');
    const unknown = run();
    expect(unknown.value.ifra.status).toBe('UNKNOWN');
    expect(unknown.warnings).toContain('fragrance.ifra_unknown');
  });
});
