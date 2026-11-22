import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { roomSpray, ROOM_SPRAY_REASONS, ROOM_SPRAY_WARNINGS, type RoomSprayInput } from './room-spray';

/* Ratios, IFRA limits and clarity observations are TEST INPUTS (sourceId TEST-FIXTURE): the engine has no built-in ratio. */
const clarity = (observation: 'clear' | 'hazy' | 'separated') => ({ observation, sourceId: 'TEST-FIXTURE', confidence: 'L1_coa_batch' as const, validFrom: '2026-01-01' });
const base = (over: Partial<RoomSprayInput> = {}): RoomSprayInput => ({
  fragranceId: 'FRAG-1',
  fragranceOilPct: '2',
  solubilizerRatio: fixture('3'),
  ratioBasis: 'trial',
  otherPct: '0.5',
  clarity: clarity('clear'),
  batchMass: qty('1000', 'g'),
  policy: {},
  ...over,
});
const run = (over: Partial<RoomSprayInput> = {}) => {
  const r = roomSpray(base(over));
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r;
};
const gaps = (over: Partial<RoomSprayInput>) => {
  const r = roomSpray(base(over));
  return r.ok ? null : r.missing.map((m) => [m.property, m.reason]);
};

describe('roomSpray (L-05)', () => {
  it('hand case 1: 1000 g, oil 2 %, ratio 3:1, other 0.5 %: solubilizer 6 % = 60 g, water 91.5 % = 915 g, oil 20 g', () => {
    const { value: r } = run();
    expect([r.fragranceOilMass.value, r.solubilizerMass.value, r.waterMass.value, r.totalMass.value]).toEqual(['20', '60', '915', '1000']);
    expect([r.solubilizerPct.value, r.waterPct.value]).toEqual(['6', '91.5']);
  });

  it('hand case 2: 2 kg, oil 1 %, ratio 2.5, no other additive: 20 g oil, 50 g solubilizer, 1930 g water', () => {
    const { value: r } = run({ fragranceOilPct: '1', solubilizerRatio: fixture('2.5'), otherPct: undefined, batchMass: qty('2', 'kg') });
    expect([r.fragranceOilMass.value, r.solubilizerMass.value, r.waterMass.value, r.waterPct.value]).toEqual(['20', '50', '1930', '96.5']);
  });

  it('hand case 3: oil 0.8 %, ratio 4 from a TDS: solubilizer 3.2 %, water 96 %; 500 g -> 4 g, 16 g, 480 g', () => {
    const { value: r } = run({ fragranceOilPct: '0.8', solubilizerRatio: fixture('4'), ratioBasis: 'tds', otherPct: undefined, batchMass: qty('500', 'g') });
    expect([r.fragranceOilMass.value, r.solubilizerMass.value, r.waterMass.value, r.solubilizerPct.value]).toEqual(['4', '16', '480', '3.2']);
  });

  it('clarity is the measured observation of a trial: clear PASS, hazy / separated FAIL, none UNKNOWN with a warning', () => {
    expect(run().value.clarity.status).toBe('PASS');
    expect(run({ clarity: clarity('hazy') }).value.clarity.status).toBe('FAIL');
    expect(run({ clarity: clarity('separated') }).value.clarity.status).toBe('FAIL');
    const unknown = run({ clarity: undefined });
    expect(unknown.value.clarity.status).toBe('UNKNOWN');
    expect(unknown.warnings).toContain(ROOM_SPRAY_WARNINGS.CLARITY_UNKNOWN);
  });

  it('a ratio without a source, a missing ratio or basis, or an invalid clarity is reported, never defaulted', () => {
    expect(gaps({ solubilizerRatio: undefined })).toEqual([['solubilizerRatio', 'input.missing']]);
    expect(gaps({ ratioBasis: undefined })).toEqual([['ratioBasis', ROOM_SPRAY_REASONS.RATIO_BASIS_REQUIRED]]);
    expect(gaps({ clarity: { ...clarity('clear'), sourceId: ' ' } })).toEqual([['clarity', ROOM_SPRAY_REASONS.CLARITY_INVALID]]);
    expect(gaps({ solubilizerRatio: { ...fixture('3'), validFrom: 'x' } })).toEqual([['solubilizerRatio', 'input.not_dated']]);
  });

  it('oil + solubilizer + other >= 100 % is rejected; the IFRA hook is UNKNOWN without a sourced limit and FAIL above it', () => {
    expect(gaps({ fragranceOilPct: '30' })).toEqual([['fragranceOilPct', 'fragrance.percents_exceed_100']]);
    const unknown = run();
    expect(unknown.value.ifra.status).toBe('UNKNOWN');
    expect(unknown.warnings).toContain('fragrance.ifra_unknown');
    expect(run({ ifraLimitPct: fixture('1.5', '%w/w') }).value.ifra.status).toBe('FAIL');
    expect(run({ ifraLimitPct: fixture('2', '%w/w') }).value.ifra.status).toBe('PASS');
  });
});
