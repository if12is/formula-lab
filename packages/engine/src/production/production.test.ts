import { describe, expect, it } from 'vitest';
import { fixture } from '../test-fixtures';
import { actualBatchCost, invoiceCostPerBaseUnit } from './actual-cost';
import { contractDecimal, toContractDecimal } from './contract-decimal';
import { checkLimits, compareWaste, evaluateDeviation } from './deviation';
import { recomputeAlkaliWithLots } from './lot-alkali';
import { weighingSheet, WEIGHING_WARNINGS } from './weighing';

// Every figure below is hand-computed (see the comment of each case). The sources are TEST-FIXTURE inputs, not real data.
const kg = (value: string) => ({ value, unit: 'kg' });
const g = (value: string) => ({ value, unit: 'g' });

describe('weighingSheet (Q-02)', () => {
  it('2.5 kg of 12 % / 1.5 % / q.s.: exact 300, 37.5, 2162.5 g; at 1 g the halves round to even (38, 2162) and the total stays 2500', () => {
    const r = weighingSheet({
      batchMass: kg('2.5'),
      resolutionG: '1',
      lines: [
        { lineNo: '1', materialId: 'A', percentWW: '12' },
        { lineNo: '2', materialId: 'B', percentWW: '1.5' },
        { lineNo: '3', materialId: 'W', qs: true },
      ],
    });
    expect(r.ok).toBe(true);
    if (!r.ok) return;
    expect(r.value.lines.map((l) => [l.materialId, l.exactG, l.weighG])).toEqual([
      ['A', '300', '300'],
      ['B', '37.5', '38'],
      ['W', '2162.5', '2162'],
    ]);
    expect(r.value.totalWeighG).toBe('2500');
    expect(r.value.rounding.hasResidual).toBe(false);
  });

  it('100 g of 33.33 / 33.33 / 33.34 % at 1 g: weights 33, 33, 33 add up to 99, the residual to the 100 g batch is reported as 1', () => {
    const r = weighingSheet({
      batchMass: g('100'),
      resolutionG: '1',
      lines: [
        { lineNo: '1', materialId: 'A', percentWW: '33.33' },
        { lineNo: '2', materialId: 'B', percentWW: '33.33' },
        { lineNo: '3', materialId: 'C', percentWW: '33.34' },
      ],
    });
    expect(r.ok).toBe(true);
    if (!r.ok) return;
    expect(r.value.lines.map((l) => l.weighG)).toEqual(['33', '33', '33']);
    expect(r.value.totalWeighG).toBe('99');
    expect(r.value.rounding.residual).toBe('1');
    expect(r.warnings).toContain(WEIGHING_WARNINGS.ROUNDING_RESIDUAL);
  });

  it('a 0.04 % line of a 100 g batch is 0.04 g: at 0.1 g it weighs 0.0 and is reported below the resolution, not dropped', () => {
    const r = weighingSheet({
      batchMass: g('100'),
      resolutionG: '0.1',
      lines: [
        { lineNo: '1', materialId: 'A', percentWW: '0.04' },
        { lineNo: '2', materialId: 'W', qs: true },
      ],
    });
    expect(r.ok).toBe(true);
    if (!r.ok) return;
    expect(r.value.belowResolution).toEqual(['1']);
    expect(r.value.lines.find((l) => l.lineNo === '1')?.weighG).toBe('0.0');
    expect(r.warnings.some((w) => w.startsWith(WEIGHING_WARNINGS.BELOW_RESOLUTION))).toBe(true);
  });

  it('adds by phase (water, oil, cool-down) unless the process steps give an order', () => {
    const lines = [
      { lineNo: '1', materialId: 'OIL', percentWW: '20', phase: 'oil' },
      { lineNo: '2', materialId: 'COOL', percentWW: '5', phase: 'cool_down' },
      { lineNo: '3', materialId: 'WATER', percentWW: '75', phase: 'water' },
    ];
    const byPhase = weighingSheet({ batchMass: kg('1'), resolutionG: '0.1', lines });
    expect(byPhase.ok && byPhase.value.lines.map((l) => l.materialId)).toEqual(['WATER', 'OIL', 'COOL']);
    const byStep = weighingSheet({ batchMass: kg('1'), resolutionG: '0.1', lines: lines.map((l) => ({ ...l, additionOrder: l.materialId === 'OIL' ? 1 : l.materialId === 'COOL' ? 2 : 3 })) });
    expect(byStep.ok && byStep.value.lines.map((l) => [l.order, l.materialId])).toEqual([[1, 'OIL'], [2, 'COOL'], [3, 'WATER']]);
  });

  it('the same inputs give the same sheet hash and a different resolution a different one', () => {
    const lines = [{ lineNo: '1', materialId: 'A', percentWW: '100' }];
    const a = weighingSheet({ batchMass: kg('1'), resolutionG: '0.1', lines });
    const b = weighingSheet({ batchMass: kg('1'), resolutionG: '0.1', lines });
    const c = weighingSheet({ batchMass: kg('1'), resolutionG: '1', lines });
    expect(a.ok && b.ok && c.ok && a.value.sheetHash === b.value.sheetHash && a.value.sheetHash !== c.value.sheetHash).toBe(true);
  });

  it('a missing resolution, a volume batch and a formula that is not 100 % are missing, not guessed', () => {
    const lines = [{ lineNo: '1', materialId: 'A', percentWW: '90' }];
    const noResolution = weighingSheet({ batchMass: kg('1'), resolutionG: null, lines });
    expect(!noResolution.ok && noResolution.missing[0]?.property).toBe('scaleResolutionG');
    const volume = weighingSheet({ batchMass: { value: '1', unit: 'L' }, resolutionG: '0.1', lines });
    expect(!volume.ok && volume.missing[0]?.reason).toBe('production.batch_unit_not_mass');
    const notHundred = weighingSheet({ batchMass: kg('1'), resolutionG: '0.1', lines });
    expect(!notHundred.ok && notHundred.missing[0]?.reason).toBe('formula.sum_not_100');
  });
});

describe('evaluateDeviation (Q-11)', () => {
  const dev = (planned: string, actual: string, thresholdPct?: string | null) => evaluateDeviation({ planned, actual, unit: 'g', thresholdPct });

  it('100 g planned, 104 g weighed, threshold 5 %: +4 g, +4 %, within', () => {
    const r = dev('100', '104', '5');
    expect(r.ok && [r.value.difference.value, r.value.differencePctDisplay, r.value.verdict]).toEqual(['4', '4.000', 'within']);
  });

  it('106 g is +6 %: exceeds 5 %; 95 g is -5 %: equal to the threshold is still within', () => {
    const high = dev('100', '106', '5');
    expect(high.ok && [high.value.verdict, high.value.exceedsThreshold]).toEqual(['exceeds', true]);
    const low = dev('100', '95', '5');
    expect(low.ok && [low.value.differencePctDisplay, low.value.verdict]).toEqual(['-5.000', 'within']);
  });

  it('no threshold set is never "within": the verdict is threshold_not_set and exceeds is null', () => {
    const r = dev('100', '101', null);
    expect(r.ok && [r.value.verdict, r.value.exceedsThreshold, r.value.thresholdPct]).toEqual(['threshold_not_set', null, null]);
  });

  it('actual equal to planned is "none" whatever the threshold', () => {
    const r = dev('250.5', '250.5', null);
    expect(r.ok && r.value.verdict).toBe('none');
  });

  it('a planned value of zero, a bad actual and a bad threshold are missing', () => {
    expect(!dev('0', '1', '5').ok).toBe(true);
    expect(!dev('100', 'abc', '5').ok).toBe(true);
    const bad = dev('100', '101', '-1');
    expect(!bad.ok && bad.missing[0]?.property).toBe('thresholdPct');
  });
});

describe('compareWaste (Q-04, reporting side)', () => {
  it('10 000 g charged, 2 % planned = 200 g; 230 g recorded is +15 %, above 10 %', () => {
    const r = compareWaste({ chargedMassG: '10000', plannedWastePct: '2', actualWasteG: '230', thresholdPct: '10' });
    expect(r.ok && [r.value.plannedWasteG.value, r.value.deviation.differencePctDisplay, r.value.deviation.verdict]).toEqual(['200', '15.000', 'exceeds']);
  });

  it('190 g recorded against 200 g is -5 %, within 10 %', () => {
    const r = compareWaste({ chargedMassG: '10000', plannedWastePct: '2', actualWasteG: '190', thresholdPct: '10' });
    expect(r.ok && [r.value.deviation.differencePctDisplay, r.value.deviation.verdict]).toEqual(['-5.000', 'within']);
  });

  it('a planned waste of 0 or 100 % cannot be compared', () => {
    expect(!compareWaste({ chargedMassG: '10000', plannedWastePct: '0', actualWasteG: '5' }).ok).toBe(true);
    expect(!compareWaste({ chargedMassG: '10000', plannedWastePct: '100', actualWasteG: '5' }).ok).toBe(true);
  });
});

describe('recomputeAlkaliWithLots (Q-03)', () => {
  const planned = fixture('190', 'mgKOH/g', 'L2_supplier_tds');
  const lot = (v: string) => fixture(v, 'mgKOH/g', 'L1_coa_batch');
  const soap = (over: object = {}) => ({
    kind: 'soap' as const,
    base: 'KOH' as const,
    oils: [{ oilId: 'OIL-A', massG: '1000', sapUnit: 'mgKOH/g' as const, planned, lot: lot('200') }],
    superfatPct: '0',
    purityPlanned: fixture('100', '%w/w', 'L2_supplier_tds'),
    purityLot: fixture('80', '%w/w', 'L1_coa_batch'),
    ...over,
  });

  it('KOH soap: 1000 g oil, SAP 190 -> 200 and purity 100 -> 80: lye 190 g -> 200/0.8 = 250 g (+60 g, +31.579 %)', () => {
    const r = recomputeAlkaliWithLots(soap(), '5');
    expect(r.ok && [r.value.plannedG, r.value.lotG, r.value.deviation.difference.value, r.value.deviation.differencePctDisplay, r.value.deviation.verdict]).toEqual(['190', '250', '60', '31.579', 'exceeds']);
  });

  it('same purity, SF 10 %: 190 x 0.9 = 171 g -> 200 x 0.9 = 180 g (+9 g, +5.263 %), threshold 10 % is within', () => {
    const r = recomputeAlkaliWithLots(soap({ superfatPct: '10', purityLot: fixture('100', '%w/w', 'L1_coa_batch') }), '10');
    expect(r.ok && [r.value.plannedG, r.value.lotG, r.value.deviation.differencePctDisplay, r.value.deviation.verdict]).toEqual(['171', '180', '5.263', 'within']);
  });

  it('a lot without a SAP on its COA stops the calculation and names the oil; the planned value is not used instead', () => {
    const r = recomputeAlkaliWithLots(
      soap({ oils: [{ oilId: 'OIL-A', massG: '600', sapUnit: 'mgKOH/g', planned, lot: lot('200') }, { oilId: 'OIL-B', massG: '400', sapUnit: 'mgKOH/g', planned, lot: null }], purityLot: null }),
    );
    expect(!r.ok && r.missing.map((m) => [m.materialId ?? null, m.property])).toEqual([['OIL-B', 'sap.lot'], [null, 'purity.lot']]);
  });

  it('KOH neutralisation: 1000 g acid, AV 180 and strength 90 % (200 g) against lot AV 190 and strength 80 % (190/0.8 = 237.5 g): +37.5 g, +18.750 %', () => {
    const r = recomputeAlkaliWithLots({
      kind: 'neutralize',
      base: 'KOH',
      form: 'solid',
      acidId: 'LABSA',
      acidMassG: '1000',
      acidValuePlanned: fixture('180', 'mgKOH/g', 'L2_supplier_tds'),
      acidValueLot: fixture('190', 'mgKOH/g', 'L1_coa_batch'),
      strengthPlanned: fixture('90', '%w/w', 'L2_supplier_tds'),
      strengthLot: fixture('80', '%w/w', 'L1_coa_batch'),
    });
    expect(r.ok && [r.value.plannedG, r.value.lotG, r.value.deviation.difference.value, r.value.deviation.differencePctDisplay]).toEqual(['200', '237.5', '37.5', '18.750']);
  });

  it('a higher acid value and a weaker lot can cancel: 180/0.9 = 200 g and 190/0.95 = 200 g give no deviation', () => {
    const r = recomputeAlkaliWithLots({
      kind: 'neutralize',
      base: 'KOH',
      form: 'solid',
      acidId: 'LABSA',
      acidMassG: '1000',
      acidValuePlanned: fixture('180', 'mgKOH/g'),
      acidValueLot: fixture('190', 'mgKOH/g', 'L1_coa_batch'),
      strengthPlanned: fixture('90', '%w/w'),
      strengthLot: fixture('95', '%w/w', 'L1_coa_batch'),
    });
    expect(r.ok && r.value.deviation.verdict).toBe('none');
  });

  it('a lot with no strength on its COA is missing', () => {
    const r = recomputeAlkaliWithLots({ kind: 'neutralize', base: 'KOH', form: 'solid', acidId: 'LABSA', acidMassG: '1000', acidValuePlanned: fixture('180', 'mgKOH/g'), acidValueLot: fixture('190', 'mgKOH/g', 'L1_coa_batch'), strengthPlanned: fixture('90', '%w/w') });
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['strength.lot']);
  });
});

describe('actualBatchCost (Q-09)', () => {
  const lines = {
    materials: [{ id: 'A', quantity: '60', unitCost: '10' }, { id: 'B', quantity: '40', unitCost: '25' }],
    packaging: [{ id: 'BOTTLE', quantity: '500', unitCost: '2.5' }, { id: 'CAP', quantity: '500', unitCost: '0.5' }],
    conversion: '400',
    units: '500',
  };

  it('materials 60x10 + 40x25 = 1600; packaging 500x2.5 + 500x0.5 = 1500; conversion 400: total 3500, 7 per unit', () => {
    const r = actualBatchCost(lines);
    expect(r.ok && [r.value.materials.value, r.value.packaging.value, r.value.total.value, r.value.perUnit.value, r.value.comparison]).toEqual(['1600', '1500', '3500', '7', null]);
  });

  it('against a computed 6.5 per unit: +0.5, +7.692 %, above a 5 % threshold', () => {
    const r = actualBatchCost({ ...lines, computedUnitCost: '6.5', thresholdPct: '5' });
    expect(r.ok && [r.value.comparison?.difference.value, r.value.comparison?.differencePctDisplay, r.value.comparison?.verdict]).toEqual(['0.5', '7.692', 'exceeds']);
  });

  it('a weight in grams costed per kg is converted exactly: 60 000 g at 10 per kg is 600, 500 pieces at 2.5 is 1250', () => {
    const r = actualBatchCost({
      materials: [{ id: 'A', quantity: '60000', quantityUnit: 'g', unitCost: '10', costUnit: 'kg' }],
      packaging: [{ id: 'BOTTLE', quantity: '500', quantityUnit: 'pc', unitCost: '2.5', costUnit: 'pc' }],
      conversion: '0',
      units: '500',
    });
    expect(r.ok && [r.value.materials.value, r.value.packaging.value, r.value.perUnit.value]).toEqual(['600', '1250', '3.7']);
  });

  it('a lot with no cost, no conversion figure and no units are all named and nothing is totalled', () => {
    const r = actualBatchCost({ ...lines, materials: [{ id: 'A', quantity: '60', unitCost: '10' }, { id: 'B', quantity: '40', unitCost: null }], conversion: null, units: '0' });
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['material.B.unitCost', 'conversion', 'units']);
  });
});

describe('checkLimits (Q-10)', () => {
  it('a value inside, on and outside the limits: 7 in 6-8 passes, 8 passes (on the limit), 8.1 fails, 5.9 fails', () => {
    const at = (value: string) => {
      const r = checkLimits({ value, min: '6', max: '8' });
      return r.ok && r.value.status;
    };
    expect([at('7'), at('8'), at('8.1'), at('5.9')]).toEqual(['PASS', 'PASS', 'FAIL', 'FAIL']);
  });

  it('a half-open limit works: max 80 only, 79.9 passes, 80.1 fails', () => {
    const at = (value: string) => {
      const r = checkLimits({ value, max: '80' });
      return r.ok && r.value.status;
    };
    expect([at('79.9'), at('80.1')]).toEqual(['PASS', 'FAIL']);
  });

  it('no limit defined is UNKNOWN, never PASS', () => {
    const r = checkLimits({ value: '7', min: null, max: null });
    expect(r.ok && [r.value.status, r.value.reason]).toEqual(['UNKNOWN', 'production.limits_not_defined']);
  });

  it('a reading that is not a decimal is missing', () => {
    expect(!checkLimits({ value: 'seven', min: '6' }).ok).toBe(true);
  });
});

describe('contract decimals and the kg column of the sheet', () => {
  it('62.5 kg, 0.0003333 kg and 5 units are written at 6 decimals, half-even: 62.500000, 0.000333, 5.000000', () => {
    expect([toContractDecimal('62.5'), toContractDecimal('0.0003333'), toContractDecimal('5')]).toEqual(['62.500000', '0.000333', '5.000000']);
    expect(toContractDecimal('0.0000005')).toBe('0.000000');
    expect(toContractDecimal('0.0000015')).toBe('0.000002');
  });

  it('a sheet line carries its exact kg: 2162.5 g is 2.162500 kg, not the rounded 2162 g', () => {
    const r = weighingSheet({ batchMass: kg('2.5'), resolutionG: '1', lines: [{ lineNo: '1', materialId: 'A', percentWW: '12' }, { lineNo: '2', materialId: 'B', percentWW: '1.5' }, { lineNo: '3', materialId: 'W', qs: true }] });
    expect(r.ok && r.value.lines.map((l) => l.consumeKg)).toEqual(['0.300000', '0.037500', '2.162500']);
  });

  it('a value that is not a decimal is missing', () => {
    expect(contractDecimal('1e3', 'quantity').ok).toBe(false);
  });
});

describe('invoiceCostPerBaseUnit (Q-09)', () => {
  it('the contract example: 2 jerrycans at 1287.50 for 50 kg is 2575 / 50 = 51.5 per kg', () => {
    const r = invoiceCostPerBaseUnit({ unitCost: '1287.50', quantity: '2.00', baseQuantity: '50.00' });
    expect(r.ok && r.value.value).toBe('51.5');
  });

  it('a bag of 25 kg at 400 (1 bag, 25 kg) is 16 per kg; 3 bags at 400 for 75 kg is the same 16', () => {
    const one = invoiceCostPerBaseUnit({ unitCost: '400', quantity: '1', baseQuantity: '25' });
    const three = invoiceCostPerBaseUnit({ unitCost: '400', quantity: '3', baseQuantity: '75' });
    expect([one.ok && one.value.value, three.ok && three.value.value]).toEqual(['16', '16']);
  });

  it('a line with no base quantity or no cost is missing, each named', () => {
    const r = invoiceCostPerBaseUnit({ unitCost: null, quantity: '2', baseQuantity: undefined });
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['unitCost', 'baseQuantity']);
  });
});
