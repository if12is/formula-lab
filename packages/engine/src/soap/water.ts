/**
 * Lye water (F-06). Three equivalent ways to state it, with deterministic conversion between them:
 *   (a) ratio             r = water / lye
 *   (b) lyeConcentration  c = lye / (lye + water) x 100        (% w/w, 0 < c < 100)
 *   (c) pctOfOils         p = water / oils x 100
 * Given any one, the water mass and the other two are computed.
 */
import { dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { resolveUserNumber, type SourcedNumberSpec } from '../sourced-input';
import { decOf, traced, type Traced } from '../trace/trace';

export type WaterMethod = 'ratio' | 'lyeConcentration' | 'pctOfOils';

export interface WaterSpec {
  method: WaterMethod;
  /** Decimal string in the method's own unit: ratio (g/g), lye % w/w, or % of oils. */
  value: string;
}

export interface WaterPlan {
  waterMass: Traced;
  /** g water per g lye (carried as grams-per-gram; no dimensionless unit exists). */
  ratioWaterToLye: Traced;
  lyeConcentrationPct: Traced;
  waterPctOfOils: Traced;
  /** Lye + water. */
  solutionMass: Traced;
}

const WATER_SPECS: Record<WaterMethod, SourcedNumberSpec> = {
  ratio: { property: 'water.ratio', label: 'Water : lye ratio (g/g)', unit: 'g', min: '0', minExclusive: true },
  lyeConcentration: { property: 'water.lyeConcentration', label: 'Lye concentration (%)', unit: '%w/w', min: '0', minExclusive: true, max: '100' },
  pctOfOils: { property: 'water.pctOfOils', label: 'Water as % of oils', unit: '%w/w', min: '0', minExclusive: true },
};

// concentration of exactly 100 means no water: reject (only 0 < c < 100 yields a finite, positive water mass).
const CONC_EXCLUDES_100 = 'water.concentration_must_be_below_100';

/** Water for a given lye mass and oils mass (both grams, as trace nodes). */
export function waterForLye(lyeMass: Traced, oilsMass: Traced, spec: WaterSpec): Result<WaterPlan> {
  const given = resolveUserNumber(spec.value, WATER_SPECS[spec.method]);
  if (!given.ok) {
    return given;
  }
  const g = given.value;
  if (spec.method === 'lyeConcentration' && g.value.gte(100)) {
    return missing([{ property: WATER_SPECS.lyeConcentration.property, reason: CONC_EXCLUDES_100 }]);
  }
  const lye = decOf(lyeMass);
  const oils = decOf(oilsMass);
  const water =
    spec.method === 'ratio'
      ? lye.mul(g.value)
      : spec.method === 'lyeConcentration'
        ? lye.mul(dec('100').minus(g.value)).div(g.value)
        : oils.mul(g.value).div(100);
  const waterT = traced('water', `Water by ${spec.method}`, water, 'g', [lyeMass, oilsMass, g.leaf]);
  const solution = lye.plus(water);
  const derived = {
    ratio: () => traced('div', 'Water : lye ratio (g/g)', water.div(lye), 'g', [waterT, lyeMass]),
    lyeConcentration: () => traced('div', 'Lye concentration (%) = lye / (lye + water)', lye.div(solution).mul(100), '%w/w', [lyeMass, waterT]),
    pctOfOils: () => traced('div', 'Water as % of oils', water.div(oils).mul(100), '%w/w', [waterT, oilsMass]),
  };
  // The method the user gave is echoed exactly (no divide/multiply round trip).
  return ok({
    waterMass: waterT,
    ratioWaterToLye: spec.method === 'ratio' ? g.leaf : derived.ratio(),
    lyeConcentrationPct: spec.method === 'lyeConcentration' ? g.leaf : derived.lyeConcentration(),
    waterPctOfOils: spec.method === 'pctOfOils' ? g.leaf : derived.pctOfOils(),
    solutionMass: traced('add', 'Solution mass = lye + water', solution, 'g', [lyeMass, waterT]),
  });
}
