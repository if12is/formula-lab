import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { leavesOf } from '../trace/trace';
import { fixture } from '../test-fixtures';
import { ACTIVE_REASONS, activeMatterOfBlend, rawMassForTarget, waterToReachTarget, type ActiveLine } from './active';

// All grade values below are TEST INPUTS (sourceId TEST-FIXTURE), not engine data.
const sles = (g: string): ActiveLine => ({ materialId: 'SLES', mass: qty(g, 'g'), contributesActive: true, activeMatter: fixture('70', '%w/w') });
const labsa = (g: string): ActiveLine => ({ materialId: 'LABSA', mass: qty(g, 'g'), contributesActive: true, activeMatter: fixture('96', '%w/w') });
const capb = (g: string): ActiveLine => ({ materialId: 'CAPB', mass: qty(g, 'g'), contributesActive: true, activeMatter: fixture('30', '%w/w') });
const water = (g: string): ActiveLine => ({ materialId: 'WATER', mass: qty(g, 'g'), contributesActive: false, activeMatter: fixture('0', '%w/w') });

describe('activeMatterOfBlend (A_total = Σ m·a / Σ m)', () => {
  it('SLES 70 / CAPB 30 / LABSA 96 blend', () => {
    // hand calc, 1000 g blend: SLES 150×0.70 = 105; CAPB 100×0.30 = 30; LABSA 20×0.96 = 19.2; water 730×0 = 0
    // active = 154.2 g; A_total = 154.2 / 1000 = 15.42 %
    const r = activeMatterOfBlend({ lines: [sles('150'), capb('100'), labsa('20'), water('730')], policy: {} });
    expect(r.ok && [r.value.activeMatterPct.value, r.value.activeMass.value, r.value.totalMass.value]).toEqual(['15.42', '154.2', '1000']);
  });

  it('a single grade returns its own active (SLES alone = 70 %)', () => {
    const r = activeMatterOfBlend({ lines: [sles('250')], policy: {} });
    expect(r.ok && r.value.activeMatterPct.value).toBe('70');
  });

  it('converts mass units (0.5 kg SLES + 500 g water: 350 / 1000 = 35 %)', () => {
    const r = activeMatterOfBlend({ lines: [{ ...sles('0'), mass: qty('0.5', 'kg') }, water('500')], policy: {} });
    expect(r.ok && r.value.activeMatterPct.value).toBe('35');
  });

  it('trace is complete: every grade leaf keeps sourceId and confidence', () => {
    const r = activeMatterOfBlend({ lines: [sles('150'), water('850')], policy: {} });
    if (!r.ok) {
      throw new Error('expected ok');
    }
    expect(r.value.activeMatterPct.trace.op).toBe('div');
    const leaves = leavesOf(r.value.activeMatterPct.trace).filter((n) => n.label.endsWith('active matter (%)'));
    expect(leaves.map((n) => [n.label, n.value, n.sourceId, n.confidence])).toEqual([
      ['SLES active matter (%)', '70', 'TEST-FIXTURE', 'L2_supplier_tds'],
      ['WATER active matter (%)', '0', 'TEST-FIXTURE', 'L2_supplier_tds'],
    ]);
  });

  it('lists EVERY contributing line without an active value (no silent zero)', () => {
    const r = activeMatterOfBlend({
      lines: [{ ...sles('100'), activeMatter: undefined }, { ...labsa('50'), activeMatter: undefined }, capb('100'), water('750')],
      policy: {},
    });
    expect(!r.ok && r.missing).toEqual([
      { materialId: 'SLES', property: 'activeMatter', reason: ACTIVE_REASONS.VALUE_REQUIRED },
      { materialId: 'LABSA', property: 'activeMatter', reason: ACTIVE_REASONS.VALUE_REQUIRED },
    ]);
  });

  it('a non-active line must declare a=0 with a source (undeclared => missing)', () => {
    const r = activeMatterOfBlend({ lines: [sles('100'), { ...water('900'), activeMatter: undefined }], policy: {} });
    expect(!r.ok && r.missing).toEqual([{ materialId: 'WATER', property: 'activeMatter', reason: ACTIVE_REASONS.NON_ACTIVE_DECLARATION_REQUIRED }]);
  });

  it('flags contradictions: contributing with a=0, or non-active with a>0', () => {
    const r = activeMatterOfBlend({
      lines: [{ ...sles('100'), activeMatter: fixture('0', '%w/w') }, { ...water('900'), activeMatter: fixture('5', '%w/w') }],
      policy: {},
    });
    expect(!r.ok && r.missing.map((m) => m.reason)).toEqual([ACTIVE_REASONS.CONTRIBUTING_BUT_ZERO, ACTIVE_REASONS.NON_ACTIVE_HAS_VALUE]);
  });

  it('reports bad inputs: empty, bad mass, out-of-range %, L7 without override, wrong unit', () => {
    expect(activeMatterOfBlend({ lines: [], policy: {} }).ok).toBe(false);
    const bad = activeMatterOfBlend({
      lines: [
        { ...sles('0') },
        { ...labsa('10'), activeMatter: fixture('101', '%w/w') },
        { ...capb('10'), activeMatter: fixture('30', 'g') },
        { ...water('10'), activeMatter: fixture('0', '%w/w', 'L7_estimate') },
        { ...sles('10'), mass: qty('1', 'L') },
      ],
      policy: {},
    });
    expect(!bad.ok && bad.missing.map((m) => [m.materialId, m.reason])).toEqual([
      ['SLES', 'input.invalid_mass'],
      ['LABSA', 'input.out_of_range'],
      ['CAPB', 'input.unit_mismatch'],
      ['WATER', 'confidence.l7_requires_signed_override'],
      ['SLES', 'input.invalid_mass'],
    ]);
  });
});

describe('rawMassForTarget (m_raw = M × A_target / a_raw)', () => {
  const run = (rawActive: string, M: string, target: string) =>
    rawMassForTarget({ batchMass: qty(M, 'g'), targetActivePct: target, rawActive: fixture(rawActive, '%w/w'), rawMaterialId: 'RAW', policy: {} });

  it('SLES 70 %: 700 g of 10 % = 700 × 10 / 70 = 100 g SLES + 600 g water', () => {
    const r = run('70', '700', '10');
    expect(r.ok && [r.value.rawMass.value, r.value.diluentMass.value]).toEqual(['100', '600']);
  });

  it('CAPB 30 %: 1000 g of 9 % = 1000 × 9 / 30 = 300 g CAPB + 700 g water', () => {
    const r = run('30', '1000', '9');
    expect(r.ok && [r.value.rawMass.value, r.value.diluentMass.value]).toEqual(['300', '700']);
  });

  it('LABSA 96 %: 480 g of 24 % = 480 × 24 / 96 = 120 g', () => {
    const r = run('96', '480', '24');
    expect(r.ok && r.value.rawMass.value).toBe('120');
  });

  it('non-terminating result stays exact to 34 digits (1000 × 12 / 70 = 171.428571...)', () => {
    const r = run('70', '1000', '12');
    expect(r.ok && r.value.rawMass.value.startsWith('171.4285714285714285714285714285')).toBe(true);
  });

  it('target = raw needs no diluent; target > raw cannot be reached', () => {
    const same = run('70', '100', '70');
    expect(same.ok && same.value.diluentMass.value).toBe('0');
    const over = run('30', '100', '40');
    expect(!over.ok && over.missing).toEqual([{ materialId: 'RAW', property: 'targetActivePct', reason: ACTIVE_REASONS.TARGET_EXCEEDS_RAW }]);
  });

  it('collects every gap: bad mass, missing raw active, bad target', () => {
    const r = rawMassForTarget({ batchMass: qty('0', 'g'), targetActivePct: 'abc', rawActive: undefined as never, policy: {} });
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['batchMass', 'rawActive', 'targetActivePct']);
  });

  it('trace carries the raw grade source', () => {
    const r = run('70', '700', '10');
    if (!r.ok) {
      throw new Error('expected ok');
    }
    expect(leavesOf(r.value.rawMass.trace).find((n) => n.label === 'RAW active matter (%)')?.sourceId).toBe('TEST-FIXTURE');
  });
});

describe('waterToReachTarget (w = m × (A_cur / A_target − 1))', () => {
  it('1000 g at 15.42 % to 10 %: 1000 × (15.42/10 − 1) = 542 g water, final 1542 g', () => {
    const r = waterToReachTarget({ existingMass: qty('1000', 'g'), currentActive: fixture('15.42', '%w/w'), targetActivePct: '10', policy: {} });
    expect(r.ok && [r.value.waterMass.value, r.value.finalMass.value]).toEqual(['542', '1542']);
  });

  it('a missing current active is reported as input.missing, not thrown', () => {
    const r = waterToReachTarget({ existingMass: qty('1000', 'g'), currentActive: undefined, targetActivePct: '10', policy: {} });
    expect(!r.ok && r.missing).toEqual([{ materialId: undefined, property: 'currentActive', reason: 'input.missing' }]);
  });

  it('chains from a computed blend (traced current active) and keeps its trace', () => {
    const blend = activeMatterOfBlend({ lines: [sles('150'), capb('100'), labsa('20'), water('730')], policy: {} });
    if (!blend.ok) {
      throw new Error('expected ok');
    }
    const r = waterToReachTarget({ existingMass: qty('1', 'kg'), currentActive: blend.value.activeMatterPct, targetActivePct: '10', policy: {} });
    expect(r.ok && r.value.waterMass.value).toBe('542');
    expect(r.ok && leavesOf(r.value.waterMass.trace).some((n) => n.sourceId === 'TEST-FIXTURE')).toBe(true);
  });

  it('SLES 70 %: 100 g to 7 % needs 100 × (70/7 − 1) = 900 g water', () => {
    const r = waterToReachTarget({ existingMass: qty('100', 'g'), currentActive: fixture('70', '%w/w'), targetActivePct: '7', policy: {} });
    expect(r.ok && r.value.waterMass.value).toBe('900');
  });

  it('target above current cannot be reached; zero current active is reported', () => {
    const up = waterToReachTarget({ existingMass: qty('100', 'g'), currentActive: fixture('10', '%w/w'), targetActivePct: '20', policy: {} });
    expect(!up.ok && up.missing[0]?.reason).toBe(ACTIVE_REASONS.TARGET_EXCEEDS_CURRENT);
    const zero = waterToReachTarget({ existingMass: qty('100', 'g'), currentActive: fixture('0', '%w/w'), targetActivePct: '20', policy: {} });
    expect(!zero.ok && zero.missing[0]?.reason).toBe(ACTIVE_REASONS.CURRENT_ACTIVE_ZERO);
  });

  it('missing mass / bad inputs are all reported', () => {
    const r = waterToReachTarget({ existingMass: qty('-1', 'g'), currentActive: fixture('x', '%w/w'), targetActivePct: '0', policy: {} });
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['existingMass', 'currentActive', 'targetActivePct']);
  });
});
