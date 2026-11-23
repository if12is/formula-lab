/**
 * T-01 golden cases, family 2: HLB / emulsifier systems and the fragrance family (fine fragrance, alcohol dilution,
 * aerosol, candle, room spray, air care, process loss). Hand arithmetic in the comments; fictional inputs.
 */
import { describe, expect, it } from 'vitest';
import {
  aerosol,
  airCareBlend,
  blendEmulsifiers,
  candle,
  daviesHLB,
  diluteEthanol,
  emulsifierSystem,
  fineFragrance,
  griffinHLB,
  processLoss,
  qty,
  requiredHLB,
  roomSpray,
} from '../../src/index';
import { HAND_TABLE, fixture, must, r9 } from './support';

describe('golden[hlb] required HLB, Griffin, Davies, two-emulsifier blend', () => {
  it('required HLB of the oil phase = Σ(m × rHLB) / Σm: (30 × 10 + 20 × 15) / 50 = 600 / 50 = 12; the water line is ignored', () => {
    const r = must(
      requiredHLB([
        { materialId: 'OIL-A', mass: '30', phase: 'oil', requiredHLB: fixture('10') },
        { materialId: 'OIL-B', mass: '20', phase: 'oil', requiredHLB: fixture('15') },
        { materialId: 'WATER', mass: '50', phase: 'water' },
      ]),
    );
    expect(r.value).toBe('12');
  });

  it('Griffin: HLB = 20 × Mh / M = 20 × 40 / 100 = 8', () => {
    expect(must(griffinHLB('40', '100')).value).toBe('8');
  });

  it('Davies: n = 1 + 2 = 3 groups; Σ Hi = 2.4 × 1 + 1.9 × 2 = 6.2; HLB = 7 + 6.2 − 0.475 × 3 = 13.2 − 1.425 = 11.775', () => {
    const r = must(daviesHLB([{ group: 'g1', h: fixture('2.4'), count: '1' }, { group: 'g2', h: fixture('1.9'), count: '2' }]));
    expect(r.value).toBe('11.775');
  });

  it('blend: f_high = (10 − 4) / (14 − 4) = 0.6 => 60 g high, 40 g low of 100 g', () => {
    const r = must(blendEmulsifiers('10', { materialId: 'LOW', hlb: fixture('4'), ionicity: 'nonionic' }, { materialId: 'HIGH', hlb: fixture('14'), ionicity: 'nonionic' }, qty('100', 'g')));
    expect([r.fractionHigh, r.fractionLow, r.high.mass.value.toFixed(), r.low.mass.value.toFixed()]).toEqual(['0.6', '0.4', '60', '40']);
  });

  it('system: rHLB 12 (previous oil phase) with 6 / 14 emulsifiers: f_high = (12 − 6) / (14 − 6) = 0.75 => 150 g high, 50 g low of 200 g', () => {
    const r = must(
      emulsifierSystem({
        oilLines: [
          { materialId: 'OIL-A', mass: '30', phase: 'oil', requiredHLB: fixture('10') },
          { materialId: 'OIL-B', mass: '20', phase: 'oil', requiredHLB: fixture('15') },
        ],
        low: { materialId: 'LOW', hlb: fixture('6'), ionicity: 'nonionic' },
        high: { materialId: 'HIGH', hlb: fixture('14'), ionicity: 'nonionic' },
        totalMass: qty('200', 'g'),
      }),
    );
    expect([r.requiredHLB, r.blend.high.mass.value.toFixed(), r.blend.low.mass.value.toFixed()]).toEqual(['12', '150', '50']);
  });

  it('an ionic emulsifier is refused (the HLB mixing rule is non-ionic only), not blended', () => {
    const r = blendEmulsifiers('10', { materialId: 'LOW', hlb: fixture('4'), ionicity: 'anionic' }, { materialId: 'HIGH', hlb: fixture('14') }, qty('100', 'g'));
    expect(!r.ok && r.missing[0]?.reason).toBe('hlb_blend_nonionic_only');
  });
});

describe('golden[fragrance] fine fragrance: oil % + fixative + carrier, ethanol by mass balance (hand OIML table)', () => {
  const base = { fragranceOilPct: '15', alcoholSource: fixture('100', '%v/v', 'L1_coa_batch'), alcoholTargetPctVV: '50', batch: { mass: qty('1000', 'g') }, policy: {}, table: HAND_TABLE };

  it('15 % oil in 1000 g, carrier 50 %v/v (= 40 %w/w) from 100 %v/v ethanol (= 100 %w/w)', () => {
    // oil 150 g ; carrier 850 g ; ethanol source 850 × 40 / 100 = 340 g ; dilution water 850 − 340 = 510 g
    // ethanol of the product 85 × 40 / 100 = 34 %w/w => 34 × 50 / 40 = 42.5 %v/v ; IFRA 20 − 15 = 5
    const r = must(fineFragrance({ ...base, ifraLimitPct: fixture('20', '%w/w', 'L1_coa_batch') }));
    expect([r.fragranceOilMass.value, r.alcoholMass.value, r.waterMass.value, r.carrierPctWW.value]).toEqual(['150', '340', '510', '85']);
    expect([r.finalEthanolPctWW.value, r.finalEthanolPctVV.value, r.ifra.status, r.ifra.marginPct?.value]).toEqual(['34', '42.5', 'PASS', '5']);
  });

  it('with 2 % fixative and 3 % added water: carrier 80 % = 800 g; ethanol 800 × 0.4 = 320; water 480 + 30 = 510; 150 + 20 + 320 + 510 = 1000', () => {
    const r = must(fineFragrance({ ...base, fixativePct: '2', addedWaterPct: '3' }));
    expect([r.fixativeMass?.value, r.alcoholMass.value, r.waterMass.value, r.finalEthanolPctVV.value]).toEqual(['20', '320', '510', '40']);
  });

  it('no IFRA certificate limit: UNKNOWN, never PASS', () => {
    expect(must(fineFragrance(base)).ifra.status).toBe('UNKNOWN');
  });

  it('an oil above the IFRA limit FAILs with a negative margin: 15 vs limit 12 => −3', () => {
    const r = must(fineFragrance({ ...base, ifraLimitPct: fixture('12', '%w/w', 'L1_coa_batch') }));
    expect([r.ifra.status, r.ifra.marginPct?.value]).toEqual(['FAIL', '-3']);
  });
});

describe('golden[alcohol_dilution] ethanol dilution by mass balance (hand OIML table)', () => {
  it('950 g of 50 %v/v from 100 %v/v: w_T = 40, w_S = 100 => source 950 × 40 / 100 = 380 g, water 570 g; V = 950 / 0.95 = 1000 mL', () => {
    // volumes before mixing: 380 / 0.8 = 475 mL + 570 / 1.0 = 570 mL = 1045 mL ; contraction 1045 − 1000 = 45 mL = 45 / 1045 × 100 = 4.306220096 %
    const r = must(diluteEthanol({ source: fixture('100', '%v/v', 'L1_coa_batch'), targetPctVV: '50', batch: { mass: qty('950', 'g') }, policy: {}, table: HAND_TABLE }));
    expect([r.sourceMass.value, r.waterMass.value, r.totalVolume.value, r.targetDensity.value]).toEqual(['380', '570', '1000', '0.95']);
    expect([r.sourceVolume.value, r.waterVolume.value, r.contractionVolume.value, r9(r.contractionPct.value)]).toEqual(['475', '570', '45', '4.306220096']);
  });

  it('the same batch given as 1000 mL: M = 1000 × 0.95 = 950 g, same masses', () => {
    const r = must(diluteEthanol({ source: fixture('100', '%v/v', 'L1_coa_batch'), targetPctVV: '50', batch: { volume: qty('1000', 'mL') }, policy: {}, table: HAND_TABLE }));
    expect([r.totalMass.value, r.sourceMass.value, r.waterMass.value]).toEqual(['950', '380', '570']);
  });

  it('a target stronger than the source cannot be made by dilution', () => {
    const r = diluteEthanol({ source: fixture('50', '%v/v'), targetPctVV: '100', batch: { mass: qty('100', 'g') }, policy: {}, table: HAND_TABLE });
    expect(!r.ok && r.missing[0]?.reason).toBe('alcohol.target_exceeds_source');
  });
});

describe('golden[aerosol_fill] concentrate : propellant split per can', () => {
  it('ratio 3 (propellant per 1 concentrate), 300 g can, 10 % oil in the concentrate, 100 cans', () => {
    // concentrate share 100 / (1 + 3) = 25 % => 75 g ; propellant 225 g ; oil 75 × 10 / 100 = 7.5 g ; oil of product 10 × 25 / 100 = 2.5 %
    // run: concentrate 75 × 100 = 7500 g ; propellant 225 × 100 = 22500 g ; rated max 350 => margin 350 − 300 = 50 PASS
    const r = must(
      aerosol({ propellantRatio: fixture('3', 'pc'), ratioBasis: 'tds', fragranceOilPctOfConcentrate: '10', netFillMass: qty('300', 'g'), cans: '100', maxFillMassG: fixture('350', 'g'), policy: {} }),
    );
    expect([r.concentrateSharePct.value, r.propellantSharePct.value, r.concentrateMassPerCan.value, r.propellantMassPerCan.value]).toEqual(['25', '75', '75', '225']);
    expect([r.oilMassPerCan.value, r.oilPctOfProduct.value, r.batch?.concentrateMass.value, r.batch?.propellantMass.value]).toEqual(['7.5', '2.5', '7500', '22500']);
    expect([r.fillCheck.status, r.fillCheck.margin?.value]).toEqual(['PASS', '50']);
  });
});

describe('golden[candle_load] fragrance load of a candle', () => {
  it('8 % load on 1000 g of wax: 80 g fragrance, 1080 g finished, 8 × 100 / 108 = 7.407407407 % of the candle; wax max 10 => margin 2 PASS; IFRA 10 => margin 2.592592593', () => {
    const r = must(candle({ loadPctOfWax: '8', waxMass: qty('1000', 'g'), waxMaxLoadPct: fixture('10', '%w/w'), ifraLimitPct: fixture('10', '%w/w', 'L1_coa_batch'), policy: {} }));
    expect([r.fragranceMass.value, r.finishedMass.value, r9(r.fragrancePctOfCandle.value)]).toEqual(['80', '1080', '7.407407407']);
    expect([r.loadCheck.status, r.loadCheck.margin?.value, r.ifra.status, r9(r.ifra.marginPct?.value)]).toEqual(['PASS', '2', 'PASS', '2.592592593']);
  });

  it('a load above the wax maximum FAILs: 12 vs 10 => −2', () => {
    const r = must(candle({ loadPctOfWax: '12', waxMass: qty('500', 'g'), waxMaxLoadPct: fixture('10', '%w/w'), policy: {} }));
    expect([r.loadCheck.status, r.loadCheck.margin?.value]).toEqual(['FAIL', '-2']);
  });
});

describe('golden[fragrance] room spray and air-care blends (percentages of 100, q.s. carrier)', () => {
  it('room spray: 3 % oil, solubilizer ratio 2 => 6 %, other 0.5 %, water 100 − 3 − 6 − 0.5 = 90.5 %; of 1000 g: 30 / 60 / 905 g', () => {
    const r = must(roomSpray({ fragranceOilPct: '3', solubilizerRatio: fixture('2', 'pc'), ratioBasis: 'tds', otherPct: '0.5', batchMass: qty('1000', 'g'), policy: {} }));
    expect([r.solubilizerPct.value, r.waterPct.value, r.fragranceOilMass.value, r.solubilizerMass.value, r.waterMass.value]).toEqual(['6', '90.5', '30', '60', '905']);
  });

  it('air care (reed diffuser style): 10 % oil + 2 % gel agent + 1 % other, 500 g: carrier 87 % = 435 g, oil 50 g, gel 10 g', () => {
    const r = must(airCareBlend({ fragranceOilPct: '10', doses: [{ key: 'gel_agent', pct: fixture('2', '%w/w') }], otherPct: '1', batchMass: qty('500', 'g'), policy: {} }));
    expect([r.fragranceOil.mass.value, r.doses[0]?.mass.value, r.remainder.pct.value, r.remainder.mass.value]).toEqual(['50', '10', '87', '435']);
  });

  it('percentages that leave no carrier are refused', () => {
    const r = roomSpray({ fragranceOilPct: '40', solubilizerRatio: fixture('2', 'pc'), ratioBasis: 'tds', batchMass: qty('100', 'g'), policy: {} });
    expect(r.ok).toBe(false);
  });
});

describe('golden[fragrance] process loss compounds by stage', () => {
  it('10 %, 20 %, 0 %: yield 0.9 × 0.8 × 1 = 0.72, total loss 28 % (not 30 %)', () => {
    const r = must(processLoss({ stages: { ageing: fixture('10', '%w/w'), chilling: fixture('20', '%w/w'), filtration: fixture('0', '%w/w') }, policy: {} }));
    expect([r.yield.value, r.totalLossPct.value]).toEqual(['0.72', '28']);
  });

  it('10 %, 20 %, 50 %: 0.9 × 0.8 × 0.5 = 0.36 => 64 %', () => {
    const r = must(processLoss({ stages: { ageing: fixture('10', '%w/w'), chilling: fixture('20', '%w/w'), filtration: fixture('50', '%w/w') }, policy: {} }));
    expect([r.yield.value, r.totalLossPct.value]).toEqual(['0.36', '64']);
  });
});
