/**
 * T-01 golden cases, family 1: alkali (saponification, neutralization) and active matter / dilution / disinfectants.
 * Every expected value is the hand result shown in the comment above it. Fictional inputs (see support.ts).
 * Marker convention: the `golden[<calculation id>]` prefix of each describe is read by coverage.golden.test.ts.
 */
import { describe, expect, it } from 'vitest';
import {
  D,
  activeMatterOfBlend,
  availableChlorineDilution,
  convertChlorineBasis,
  disinfectantActive,
  neutralize,
  qty,
  rawMassForTarget,
  soapLye,
  waterToReachTarget,
  type SaponifiableOil,
} from '../../src/index';
import { HAND_TABLE, fixture, must, r9 } from './support';

const oil = (id: string, g: string, sap: string, extra: Partial<SaponifiableOil['sap']> = {}): SaponifiableOil => ({
  oilId: id,
  mass: qty(g, 'g'),
  sap: { unit: 'gNaOH/g', typ: fixture(sap), ...extra },
});
const pure = fixture('100', '%w/w', 'L1_coa_batch');

describe('golden[saponification] lye = Σ(m × SAP) × (1 − SF/100) / purity', () => {
  it('NaOH from a gNaOH/g SAP: 1000 g × 0.14 = 140; SF 5 % => 140 × 0.95 = 133; purity 100 % => 133 g', () => {
    const r = must(soapLye({ oils: [oil('OIL-X', '1000', '0.14')], base: 'NaOH', purity: pure, superfatPct: '5', policy: {} }));
    expect(r.used.value).toBe('133');
    expect(r.typ.value).toBe('133');
    expect(r.oilsMass.value).toBe('1000');
  });

  it('KOH from a mgKOH/g SAP: 800 g × 190/1000 = 152; SF 8 % => 152 × 0.92 = 139.84; purity 80 % => 139.84 / 0.8 = 174.8 g', () => {
    const o: SaponifiableOil = { oilId: 'OIL-Y', mass: qty('800', 'g'), sap: { unit: 'mgKOH/g', typ: fixture('190') } };
    const r = must(soapLye({ oils: [o], base: 'KOH', purity: fixture('80', '%w/w', 'L1_coa_batch'), superfatPct: '8', policy: {} }));
    expect(r.used.value).toBe('174.8');
  });

  it('a COA lot SAP wins over typ for "used" while typ stays reported: A 600 g (typ 0.14, lot 0.142), B 400 g (0.128), SF 0', () => {
    // used: 600 × 0.142 = 85.2 ; + 400 × 0.128 = 51.2 ; = 136.4        typ: 600 × 0.14 = 84 ; + 51.2 = 135.2
    const oils = [oil('OIL-A', '600', '0.14', { lot: fixture('0.142', undefined, 'L1_coa_batch') }), oil('OIL-B', '400', '0.128')];
    const r = must(soapLye({ oils, base: 'NaOH', purity: pure, superfatPct: '0', policy: {} }));
    expect([r.used.value, r.typ.value]).toEqual(['136.4', '135.2']);
    expect(r.usedBasis).toEqual({ 'OIL-A': 'lot', 'OIL-B': 'typ' });
    expect(r.min).toBeUndefined();
  });

  it('exactly one mole: 1000 g oil at SAP 56.106 mgKOH/g = 56.106 g KOH; as NaOH 56.106 × (39.997 / 56.106) = 39.997 g', () => {
    const o: SaponifiableOil = { oilId: 'OIL-Z', mass: qty('1000', 'g'), sap: { unit: 'mgKOH/g', typ: fixture('56.106') } };
    const r = must(soapLye({ oils: [o], base: 'NaOH', purity: pure, superfatPct: '0', policy: {} }));
    expect(r9(r.used.value)).toBe('39.997');
  });

  it('lye water, three ways of stating it (lye 140 g from 1000 g × 0.14, SF 0, purity 100)', () => {
    const run = (method: 'ratio' | 'lyeConcentration' | 'pctOfOils', value: string) =>
      must(soapLye({ oils: [oil('OIL-X', '1000', '0.14')], base: 'NaOH', purity: pure, superfatPct: '0', water: { method, value }, policy: {} })).water!;
    // ratio 2.5: water = 140 × 2.5 = 350 ; solution 490 ; % of oils = 350 / 1000 × 100 = 35
    const ratio = run('ratio', '2.5');
    expect([ratio.waterMass.value, ratio.solutionMass.value, ratio.waterPctOfOils.value]).toEqual(['350', '490', '35']);
    // lye concentration 28 %: water = 140 × (100 − 28) / 28 = 140 × 72 / 28 = 360 ; solution 500 ; % of oils 36
    const conc = run('lyeConcentration', '28');
    expect([conc.waterMass.value, conc.solutionMass.value, conc.waterPctOfOils.value]).toEqual(['360', '500', '36']);
    // water 36 % of the oils: 1000 × 36 / 100 = 360 ; concentration = 140 / 500 × 100 = 28
    const pct = run('pctOfOils', '36');
    expect([pct.waterMass.value, pct.lyeConcentrationPct.value]).toEqual(['360', '28']);
  });
});

describe('golden[neutralization] base = m × AV/1000 × f, reagent = base / strength', () => {
  const acid = { acidMass: qty('1000', 'g'), acidValue: fixture('200', 'mgKOH/g', 'L1_coa_batch'), policy: {} };

  it('KOH solid at 80 %: 1000 g × 200/1000 = 200 g pure; 200 / 0.8 = 250 g of the solid grade', () => {
    const r = must(neutralize({ ...acid, base: 'KOH', form: 'solid', strength: fixture('80', '%w/w', 'L1_coa_batch') }));
    expect([r.pureBaseMass.value, r.reagentMass.value, r.waterInReagent]).toEqual(['200', '250', undefined]);
  });

  it('KOH solution at 50 %: 200 g pure => 200 / 0.5 = 400 g of solution, of which 400 − 200 = 200 g is water', () => {
    const r = must(neutralize({ ...acid, base: 'KOH', form: 'solution', strength: fixture('50', '%w/w', 'L1_coa_batch') }));
    expect([r.pureBaseMass.value, r.reagentMass.value, r.waterInReagent?.value]).toEqual(['200', '400', '200']);
  });

  it('one mole: AV 56.106 mgKOH/g, 1000 g, as NaOH 100 %: 56.106 × 39.997 / 56.106 = 39.997 g', () => {
    const r = must(neutralize({ acidMass: qty('1000', 'g'), acidValue: fixture('56.106', 'mgKOH/g'), base: 'NaOH', form: 'solid', strength: fixture('100', '%w/w'), policy: {} }));
    expect([r9(r.pureBaseMass.value), r9(r.reagentMass.value)]).toEqual(['39.997', '39.997']);
  });
});

describe('golden[active_matter] A_total = Σ(m × a) / Σm', () => {
  it('300 g at 70 % + 50 g at 100 % + 650 g of a declared non-active water: (210 + 50 + 0) / 1000 = 26 %', () => {
    const r = must(
      activeMatterOfBlend({
        lines: [
          { materialId: 'A', mass: qty('300', 'g'), contributesActive: true, activeMatter: fixture('70', '%w/w') },
          { materialId: 'B', mass: qty('50', 'g'), contributesActive: true, activeMatter: fixture('100', '%w/w') },
          { materialId: 'W', mass: qty('650', 'g'), contributesActive: false, activeMatter: fixture('0', '%w/w') },
        ],
        policy: {},
      }),
    );
    expect([r.activeMass.value, r.totalMass.value, r.activeMatterPct.value]).toEqual(['260', '1000', '26']);
  });
});

describe('golden[dilution] m_raw = M × A_target / a_raw and water = m × (A_cur / A_target − 1)', () => {
  it('5000 g batch at 12 % from a 60 % grade: 5000 × 12 / 60 = 1000 g raw, 5000 − 1000 = 4000 g diluent', () => {
    const r = must(rawMassForTarget({ batchMass: qty('5000', 'g'), targetActivePct: '12', rawActive: fixture('60', '%w/w'), policy: {} }));
    expect([r.rawMass.value, r.diluentMass.value]).toEqual(['1000', '4000']);
  });

  it('2000 g at 30 % brought to 12 %: water = 2000 × (30/12 − 1) = 2000 × 1.5 = 3000 g, final 5000 g', () => {
    const r = must(waterToReachTarget({ existingMass: qty('2000', 'g'), currentActive: fixture('30', '%w/w'), targetActivePct: '12', policy: {} }));
    expect([r.waterMass.value, r.finalMass.value]).toEqual(['3000', '5000']);
  });

  it('chained: the 26 % blend of the previous family (1000 g) to 13 % needs 1000 × (26/13 − 1) = 1000 g of water', () => {
    const blend = must(activeMatterOfBlend({ lines: [{ materialId: 'A', mass: qty('1000', 'g'), contributesActive: true, activeMatter: fixture('26', '%w/w') }], policy: {} }));
    const r = must(waterToReachTarget({ existingMass: qty('1000', 'g'), currentActive: blend.activeMatterPct, targetActivePct: '13', policy: {} }));
    expect(r.waterMass.value).toBe('1000');
  });

  it('a target above the raw grade cannot be reached by dilution: missing, not a negative water', () => {
    const r = rawMassForTarget({ batchMass: qty('100', 'g'), targetActivePct: '70', rawActive: fixture('60', '%w/w'), policy: {} });
    expect(!r.ok && r.missing[0]?.reason).toBe('active.target_exceeds_raw');
  });
});

describe('golden[dilution] available chlorine (bases NaOCl / Cl2, measured storage loss)', () => {
  const coa = (v: string) => fixture(v, '%w/w', 'L1_coa_batch');

  it('1 mol = 1 mol: 74.44 %NaOCl is 74.44 × 70.90 / 74.44 = 70.9 %Cl2, and back', () => {
    expect(r9(convertChlorineBasis(new D('74.44'), 'NaOCl', 'Cl2').toFixed())).toBe('70.9');
    expect(r9(convertChlorineBasis(new D('70.9'), 'Cl2', 'NaOCl').toFixed())).toBe('74.44');
  });

  it('same basis, no storage: 3000 g at 3.6 % from a 12 % COA: 3000 × 3.6 / 12 = 900 g raw, 2100 g diluent', () => {
    const r = must(availableChlorineDilution({ coa: coa('12'), coaBasis: 'NaOCl', targetPct: '3.6', targetBasis: 'NaOCl', batchMass: qty('3000', 'g'), policy: {} }));
    expect([r.rawMass.value, r.diluentMass.value]).toEqual(['900', '2100']);
  });

  it('storage loss measured 0 % at day 0 and 20 % at day 30: at day 15 the loss is 10 %; fill target 3.6 / 0.9 = 4 %; raw = 3000 × 4 / 12 = 1000 g', () => {
    const r = must(
      availableChlorineDilution({
        coa: coa('12'),
        coaBasis: 'NaOCl',
        targetPct: '3.6',
        targetBasis: 'NaOCl',
        batchMass: qty('3000', 'g'),
        storage: { lossPoints: [{ days: '0', lossPct: fixture('0', '%w/w') }, { days: '30', lossPct: fixture('20', '%w/w') }], days: '15' },
        policy: {},
      }),
    );
    expect([r.lossPct?.value, r.fillTargetPct.value, r.rawMass.value, r.diluentMass.value]).toEqual(['10', '4', '1000', '2000']);
  });

  it('target on the Cl2 basis, COA on NaOCl: 7.09 %Cl2 = 7.09 × 74.44 / 70.9 = 7.444 %NaOCl; 1000 g from a 14.888 % COA: 1000 × 7.444 / 14.888 = 500 g', () => {
    const r = must(availableChlorineDilution({ coa: coa('14.888'), coaBasis: 'NaOCl', targetPct: '7.09', targetBasis: 'Cl2', batchMass: qty('1000', 'g'), policy: {} }));
    expect([r9(r.targetOnCoaBasisPct.value), r9(r.rawMass.value), r9(r.diluentMass.value)]).toEqual(['7.444', '500', '500']);
  });
});

describe('golden[disinfectant_active] ethanol %v/v and quaternary ammonium %w/w of the finished product', () => {
  it('quat: 100 g at 50 % active + 900 g of declared non-active water: 50 / 1000 = 5 %w/w', () => {
    const r = must(
      disinfectantActive({
        kind: 'quat',
        lines: [
          { materialId: 'Q', mass: qty('100', 'g'), contributesActive: true, activeMatter: fixture('50', '%w/w') },
          { materialId: 'W', mass: qty('900', 'g'), contributesActive: false, activeMatter: fixture('0', '%w/w') },
        ],
        policy: {},
      }),
    );
    expect([r.kind, r.concentration.value]).toEqual(['quat', '5']);
  });

  it('ethanol (hand table): 700 g of 100 %w/w + 300 g water => 700 / 1000 = 70 %w/w => 50 + (70 − 40) × 50/60 = 75 %v/v; minimum 70 => margin 5, meets', () => {
    const lines = [
      { materialId: 'ETOH', mass: qty('700', 'g'), contributesEthanol: true, strength: fixture('100', '%w/w', 'L1_coa_batch') },
      { materialId: 'WATER', mass: qty('300', 'g'), contributesEthanol: false },
    ];
    const r = must(disinfectantActive({ kind: 'alcohol', lines, policy: {}, table: HAND_TABLE, requiredMinPctVV: '70' }));
    if (r.kind !== 'alcohol') {
      throw new Error('expected alcohol');
    }
    expect([r.ethanolMass.value, r.totalMass.value, r.ethanolPctWW.value, r.concentration.value, r.requirementMargin?.value, r.meetsRequirement]).toEqual(['700', '1000', '70', '75', '5', true]);
    const strict = must(disinfectantActive({ kind: 'alcohol', lines, policy: {}, table: HAND_TABLE, requiredMinPctVV: '80' }));
    expect(strict.kind === 'alcohol' && [strict.requirementMargin?.value, strict.meetsRequirement]).toEqual(['-5', false]);
  });
});
