import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { candle, CANDLE_WARNINGS, type CandleInput } from './candle';

/* The wax maximum load and IFRA limits are TEST INPUTS (sourceId TEST-FIXTURE): the engine has no built-in limit. */
const base = (over: Partial<CandleInput> = {}): CandleInput => ({
  fragranceId: 'FRAG-1',
  loadPctOfWax: '25',
  waxMass: qty('400', 'g'),
  policy: {},
  ...over,
});
const run = (over: Partial<CandleInput> = {}) => {
  const r = candle(base(over));
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r;
};

describe('candle (L-07, IFRA 12)', () => {
  it('hand case 1: 400 g wax, load 25 %: 100 g fragrance, 500 g candle, 20 % of the finished candle', () => {
    const { value: c } = run();
    expect([c.fragranceMass.value, c.finishedMass.value, c.fragrancePctOfCandle.value]).toEqual(['100', '500', '20']);
  });

  it('hand case 2: 0.2 kg wax, load 100 %: 200 g fragrance, 400 g candle, 50 %', () => {
    const { value: c } = run({ loadPctOfWax: '100', waxMass: qty('0.2', 'kg') });
    expect([c.fragranceMass.value, c.finishedMass.value, c.fragrancePctOfCandle.value]).toEqual(['200', '400', '50']);
  });

  it('hand case 3: 1000 g wax, load 8 %: 80 g fragrance, 1080 g candle, 800 / 108 = 7.407407... % of the finished candle', () => {
    const { value: c } = run({ loadPctOfWax: '8', waxMass: qty('1000', 'g') });
    expect([c.fragranceMass.value, c.finishedMass.value]).toEqual(['80', '1080']);
    expect(c.fragrancePctOfCandle.value.startsWith('7.4074074')).toBe(true);
  });

  it('the load check needs the wax TDS maximum: 8 % against 10 PASS (margin 2), against 6 FAIL (margin -2), none UNKNOWN with a warning', () => {
    const pass = run({ loadPctOfWax: '8', waxMaxLoadPct: fixture('10', '%w/w') }).value.loadCheck;
    expect([pass.status, pass.margin?.value]).toEqual(['PASS', '2']);
    const fail = run({ loadPctOfWax: '8', waxMaxLoadPct: fixture('6', '%w/w') }).value.loadCheck;
    expect([fail.status, fail.margin?.value]).toEqual(['FAIL', '-2']);
    const unknown = run();
    expect(unknown.value.loadCheck.status).toBe('UNKNOWN');
    expect(unknown.warnings).toContain(CANDLE_WARNINGS.WAX_LIMIT_UNKNOWN);
  });

  it('the IFRA hook checks the finished-candle %: 20 % against 15 FAIL, against 20 PASS, no limit UNKNOWN', () => {
    expect(run({ ifraLimitPct: fixture('15', '%w/w') }).value.ifra.status).toBe('FAIL');
    expect(run({ ifraLimitPct: fixture('20', '%w/w') }).value.ifra.status).toBe('PASS');
    expect(run().value.ifra.status).toBe('UNKNOWN');
  });

  it('a missing or non-positive load or wax mass is reported, never defaulted', () => {
    const r = candle(base({ loadPctOfWax: '0' }));
    expect(r.ok ? null : r.missing.map((m) => [m.property, m.reason])).toEqual([['loadPctOfWax', 'input.out_of_range']]);
    const w = candle(base({ waxMass: qty('0', 'g') }));
    expect(w.ok ? null : w.missing.map((m) => [m.property, m.reason])).toEqual([['waxMass', 'input.invalid_mass']]);
  });
});
