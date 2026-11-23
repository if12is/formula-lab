import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { airCareBlend, type AirCareBlendInput } from './air-care-blend';

/* Doses and IFRA limits are TEST INPUTS (sourceId TEST-FIXTURE): the engine has no built-in dose. */
const base = (over: Partial<AirCareBlendInput> = {}): AirCareBlendInput => ({
  fragranceId: 'FRAG-1',
  fragranceOilPct: '10',
  batchMass: qty('500', 'g'),
  policy: {},
  ...over,
});
const run = (over: Partial<AirCareBlendInput> = {}) => {
  const r = airCareBlend(base(over));
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r;
};
const gaps = (over: Partial<AirCareBlendInput>) => {
  const r = airCareBlend(base(over));
  return r.ok ? null : r.missing.map((m) => [m.property, m.reason]);
};

describe('airCareBlend (L-07 reed diffuser / fragrance oil, L-08 gel and car)', () => {
  it('hand case 1, reed diffuser: 500 g, oil 10 %, solvent q.s.: 50 g oil, 450 g solvent (90 %)', () => {
    const { value: b } = run();
    expect([b.fragranceOil.mass.value, b.remainder.pct.value, b.remainder.mass.value, b.totalMass.value]).toEqual(['50', '90', '450', '500']);
    expect(b.doses).toEqual([]);
  });

  it('hand case 2, gel: 1 kg, oil 3 %, gel agent 2.5 % (a TDS dose), other 0.5 %, water q.s.: 30 g, 25 g, water 94 % = 940 g', () => {
    const { value: b } = run({ fragranceOilPct: '3', batchMass: qty('1', 'kg'), doses: [{ key: 'gel_agent', pct: fixture('2.5', '%w/w') }], otherPct: '0.5' });
    expect([b.fragranceOil.mass.value, b.doses[0]?.key, b.doses[0]?.mass.value, b.remainder.pct.value, b.remainder.mass.value]).toEqual(['30', 'gel_agent', '25', '94', '940']);
  });

  it('hand case 3, car freshener: 250 g, oil 20 %, carrier q.s.: 50 g oil, 200 g carrier', () => {
    const { value: b } = run({ fragranceOilPct: '20', batchMass: qty('250', 'g') });
    expect([b.fragranceOil.mass.value, b.remainder.pct.value, b.remainder.mass.value]).toEqual(['50', '80', '200']);
  });

  it('a dose without a source, or oil + doses + other of 100 % or more, is reported, never defaulted', () => {
    expect(gaps({ doses: [{ key: 'gel_agent', pct: undefined }] })).toEqual([['gel_agentPct', 'input.missing']]);
    expect(gaps({ fragranceOilPct: '60', doses: [{ key: 'gel_agent', pct: fixture('40', '%w/w') }] })).toEqual([['fragranceOilPct', 'fragrance.percents_exceed_100']]);
    expect(gaps({ fragranceOilPct: '0' })).toEqual([['fragranceOilPct', 'input.out_of_range']]);
  });

  it('the IFRA hook is UNKNOWN without a sourced limit (never PASS) and FAIL above it: 10 % against 8 FAIL, against 10 PASS', () => {
    const unknown = run();
    expect(unknown.value.ifra.status).toBe('UNKNOWN');
    expect(unknown.warnings).toContain('fragrance.ifra_unknown');
    expect(run({ ifraLimitPct: fixture('8', '%w/w') }).value.ifra.status).toBe('FAIL');
    expect(run({ ifraLimitPct: fixture('10', '%w/w') }).value.ifra.status).toBe('PASS');
  });
});
