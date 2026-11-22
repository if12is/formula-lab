import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { DISINFECTANT_REASONS, disinfectantActive, type EthanolLine } from './active';

/*
 * Hand checks use rows of data/oiml-r22/table-20c.json that are hit EXACTLY (OIML R 22, 20 C):
 *   %w/w 40.0 -> %v/v 47.3947631607 ; 50.0 -> 57.8893372141 ; 90.0 -> 93.2664044525 ; 100.0 -> 100
 * Strengths below are TEST INPUTS (sourceId TEST-FIXTURE), not engine data.
 */
const ethanol = (id: string, grams: string, strength: string, unit: '%w/w' | '%v/v' | null = '%w/w'): EthanolLine => ({
  materialId: id,
  mass: qty(grams, 'g'),
  contributesEthanol: true,
  strength: fixture(strength, unit ?? undefined),
});
const water = (grams: string): EthanolLine => ({ materialId: 'WATER', mass: qty(grams, 'g'), contributesEthanol: false });

function alcohol(lines: EthanolLine[], requiredMinPctVV?: string) {
  const r = disinfectantActive({ kind: 'alcohol', lines, policy: {}, ...(requiredMinPctVV === undefined ? {} : { requiredMinPctVV }) });
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  if (r.value.kind !== 'alcohol') {
    throw new Error('expected alcohol');
  }
  return r.value;
}

describe('alcohol disinfectant: ethanol %v/v of the finished product (OIML R 22)', () => {
  it('500 g of 80 %w/w + 500 g water: ethanol 400 g / 1000 g = 40 %w/w = 47.3947631607 %v/v', () => {
    const r = alcohol([ethanol('ETOH', '500', '80'), water('500')]);
    expect([r.ethanolMass.value, r.totalMass.value, r.ethanolPctWW.value]).toEqual(['400', '1000', '40']);
    expect(r.concentration.value).toBe('47.3947631607');
    expect(r.concentration.unit).toBe('%v/v');
  });

  it('1000 g of 90 %w/w alone: 90 %w/w = 93.2664044525 %v/v', () => {
    const r = alcohol([ethanol('ETOH', '1000', '90')]);
    expect(r.ethanolPctWW.value).toBe('90');
    expect(r.concentration.value).toBe('93.2664044525');
  });

  it('two ethanol lines: 100 g of 100 %v/v (= 100 %w/w) + 500 g of 80 %w/w (400 g) + 400 g water = 500/1000 = 50 %w/w = 57.8893372141 %v/v', () => {
    const r = alcohol([ethanol('ABS', '100', '100', '%v/v'), ethanol('ETOH', '500', '80'), water('400')]);
    expect(r.ethanolMass.value).toBe('500');
    expect(r.concentration.value).toBe('57.8893372141');
  });

  it('a typed requirement gives the margin: 47.3947631607 against a minimum of 45 is +2.3947631607 (met); against 50 it is -2.6052368393 (not met)', () => {
    const lines = [ethanol('ETOH', '500', '80'), water('500')];
    const met = alcohol(lines, '45');
    expect(met.requirementMargin?.value).toBe('2.3947631607');
    expect(met.meetsRequirement).toBe(true);
    const short = alcohol(lines, '50');
    expect(short.requirementMargin?.value).toBe('-2.6052368393');
    expect(short.meetsRequirement).toBe(false);
  });

  it('with no requirement typed there is no verdict at all (the engine holds no efficacy threshold)', () => {
    const r = alcohol([ethanol('ETOH', '500', '80'), water('500')]);
    expect(r.meetsRequirement).toBeUndefined();
    expect(r.requirementMargin).toBeUndefined();
  });

  it('the trace reaches the COA strength and the OIML lookup', () => {
    const r = alcohol([ethanol('ETOH', '500', '80'), water('500')]);
    expect(r.concentration.trace.op).toBe('oiml');
    expect(JSON.stringify(r.concentration.trace)).toContain('TEST-FIXTURE');
  });
});

describe('alcohol disinfectant: what is missing is listed, never read as zero', () => {
  const run = (lines: EthanolLine[], required?: string) => disinfectantActive({ kind: 'alcohol', lines, policy: {}, ...(required === undefined ? {} : { requiredMinPctVV: required }) });
  const reasonsOf = (r: ReturnType<typeof run>): string[] => (r.ok ? [] : r.missing.map((m) => m.reason));

  it('an ethanol line with no COA strength', () => {
    const r = run([{ materialId: 'ETOH', mass: qty('500', 'g'), contributesEthanol: true }, water('500')]);
    expect(r.ok).toBe(false);
    expect(r.ok ? [] : r.missing.map((m) => m.materialId)).toContain('ETOH');
  });

  it('a strength with no stated basis is alcohol.basis_required', () => {
    expect(reasonsOf(run([ethanol('ETOH', '500', '80', null), water('500')]))).toContain('alcohol.basis_required');
  });

  it('no line carries ethanol, or no lines at all', () => {
    expect(reasonsOf(run([water('500')]))).toEqual([DISINFECTANT_REASONS.NO_ETHANOL_LINE]);
    expect(reasonsOf(run([]))).toEqual([DISINFECTANT_REASONS.NO_LINES]);
  });

  it('a material declared as carrying no ethanol must not bring a strength', () => {
    const line: EthanolLine = { ...water('500'), strength: fixture('5', '%w/w') };
    expect(reasonsOf(run([ethanol('ETOH', '500', '80'), line]))).toEqual([DISINFECTANT_REASONS.NON_ETHANOL_HAS_STRENGTH]);
  });

  it('every gap is reported together, with a bad requirement', () => {
    const r = run([{ materialId: 'ETOH', mass: qty('500', 'g'), contributesEthanol: true }, { materialId: 'IPA', mass: qty('100', 'g'), contributesEthanol: true }], '0');
    expect(r.ok ? [] : r.missing.map((m) => m.property)).toEqual(['ethanolStrength', 'ethanolStrength', 'requiredMinPctVV']);
  });

  it('a mass that is not a mass is refused', () => {
    expect(run([{ ...ethanol('ETOH', '1', '80'), mass: qty('1', 'L') }]).ok).toBe(false);
  });
});

describe('quaternary ammonium disinfectant: active %w/w of the finished product', () => {
  const line = (id: string, grams: string, active: string) => ({ materialId: id, mass: qty(grams, 'g'), contributesActive: Number(active) > 0, activeMatter: fixture(active, '%w/w') });
  const quat = (lines: ReturnType<typeof line>[]) => {
    const r = disinfectantActive({ kind: 'quat', lines, policy: {} });
    if (!r.ok) {
      throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
    }
    if (r.value.kind !== 'quat') {
      throw new Error('expected quat');
    }
    return r.value;
  };

  it('100 g of a 50 % active concentrate + 900 g water: 50 g / 1000 g = 5 %w/w', () => {
    const r = quat([line('QUAT', '100', '50'), line('WATER', '900', '0')]);
    expect([r.kind, r.concentration.value, r.concentration.unit, r.activeMass.value]).toEqual(['quat', '5', '%w/w', '50']);
  });

  it('two quaternary materials: 200 g at 80 % + 100 g at 10 % + 700 g water = (160 + 10) / 1000 = 17 %w/w', () => {
    expect(quat([line('QUAT-A', '200', '80'), line('QUAT-B', '100', '10'), line('WATER', '700', '0')]).concentration.value).toBe('17');
  });

  it('250 g of 40 % + 750 g water = 100 / 1000 = 10 %w/w', () => {
    expect(quat([line('QUAT', '250', '40'), line('WATER', '750', '0')]).concentration.value).toBe('10');
  });

  it('a quaternary material with no active value is missing, not zero', () => {
    const r = disinfectantActive({ kind: 'quat', lines: [{ materialId: 'QUAT', mass: qty('100', 'g'), contributesActive: true }, line('WATER', '900', '0')], policy: {} });
    expect(r.ok).toBe(false);
    expect(r.ok ? [] : r.missing.map((m) => m.reason)).toContain('active.value_required');
  });
});
