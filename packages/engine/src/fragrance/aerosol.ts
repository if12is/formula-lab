/**
 * Aerosol air freshener (L-06). Pure, decimal.js only.
 *   concentrate : propellant is a MASS ratio typed as parts of propellant per 1 part of concentrate (4 means 20:80).
 *   concentrate share = 100 / (1 + r) %, propellant share = 100 r / (1 + r) %, gas mass per can = net fill x propellant share.
 * The ratio is NEVER built in: it comes from the filler's / propellant supplier's TDS or from a documented trial (`ratioBasis`),
 * as a sourced value. The same goes for the rated maximum net fill of the can: no sourced maximum, no PASS.
 * The one warning the sources state (plan/00-research/05-product-specialties.md, section 6) is always emitted: an aerosol needs a
 * pressurised filling line and safety conditions. No pressure, flammability or storage limit is in the sources, so none is built in
 * (HUMAN-TODO). The IFRA hook is the shared checkIfraLimit on the oil % of the filled product (category 10B).
 */
import type { MissingData } from '../contract';
import type { CheckStatus } from '../core/check-status';
import { dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import { qty } from '../core/quantity';
import { all, flatMap, missing, ok, type Result } from '../core/result';
import { resolveMassG, resolveSourcedNumber, resolveUserNumber, type ResolvedNumber } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { FRAGRANCE_WARNINGS, checkIfraLimit, type IfraCheck } from './fine-fragrance';
import { RATIO_BASES, type RatioBasis } from './room-spray';

export const AEROSOL_REASONS = {
  RATIO_BASIS_REQUIRED: 'fragrance.aerosol_ratio_basis_required',
  CANS_INVALID: 'fragrance.aerosol_cans_invalid',
} as const;

export const AEROSOL_WARNINGS = {
  FILLING_LINE: 'fragrance.aerosol_filling_line',
  FILL_LIMIT_UNKNOWN: 'fragrance.aerosol_fill_limit_unknown',
} as const;

export interface AerosolInput {
  fragranceId?: string;
  /** Propellant mass per 1 mass of concentrate. Sourced: TDS or a documented trial. */
  propellantRatio: SourcedScalar | undefined;
  ratioBasis: RatioBasis | undefined;
  /** Fragrance oil, % w/w of the concentrate (a typed requirement). */
  fragranceOilPctOfConcentrate: string;
  /** Net fill of one can. */
  netFillMass: ReturnType<typeof qty>;
  /** Cans to fill (a whole number); absent means the per-can figures only. */
  cans?: string;
  /** Rated maximum net fill of the can, grams. Sourced; absent leaves the fill check UNKNOWN. */
  maxFillMassG?: SourcedScalar;
  ifraLimitPct?: SourcedScalar;
  policy: ConfidencePolicy;
}

export interface Aerosol {
  concentrateSharePct: Traced;
  propellantSharePct: Traced;
  concentrateMassPerCan: Traced;
  /** Gas mass per can. */
  propellantMassPerCan: Traced;
  oilMassPerCan: Traced;
  /** Fragrance oil, % w/w of the filled product. */
  oilPctOfProduct: Traced;
  batch?: { cans: Traced; concentrateMass: Traced; propellantMass: Traced };
  fillCheck: { status: CheckStatus; limit?: Traced; margin?: Traced; missing: MissingData[] };
  ifra: IfraCheck;
}

function resolveCans(cans: string | undefined, fragranceId: string | undefined): Result<ResolvedNumber | undefined> {
  if (cans === undefined) {
    return ok(undefined);
  }
  const n = resolveUserNumber(cans, { materialId: fragranceId, property: 'cans', label: 'Cans to fill', unit: 'pc', min: '1' });
  if (!n.ok) {
    return n;
  }
  return n.value.value.isInteger() ? ok(n.value) : missing([{ materialId: fragranceId, property: 'cans', reason: AEROSOL_REASONS.CANS_INVALID }]);
}

export function aerosol(input: AerosolInput): Result<Aerosol> {
  const basis: Result<RatioBasis> =
    input.ratioBasis !== undefined && (RATIO_BASES as readonly string[]).includes(input.ratioBasis)
      ? ok(input.ratioBasis)
      : missing([{ materialId: input.fragranceId, property: 'ratioBasis', reason: AEROSOL_REASONS.RATIO_BASIS_REQUIRED }]);
  const inputs = all([
    resolveSourcedNumber(
      input.propellantRatio,
      { materialId: input.fragranceId, property: 'propellantRatio', label: 'Propellant : concentrate ratio (mass per mass)', unit: 'pc', min: '0', minExclusive: true },
      input.policy,
    ),
    basis,
    resolveUserNumber(input.fragranceOilPctOfConcentrate, {
      materialId: input.fragranceId,
      property: 'fragranceOilPct',
      label: 'Fragrance oil in the concentrate (%)',
      unit: '%w/w',
      min: '0',
      minExclusive: true,
      max: '100',
    }),
    resolveMassG(input.fragranceId, 'netFillMass', 'Net fill per can (g)', input.netFillMass),
    resolveCans(input.cans, input.fragranceId),
  ] as const);
  return flatMap(inputs, ([ratio, , oil, fill, cans]): Result<Aerosol> => {
    const parts = ratio.value.plus(1);
    const concentrateShare = traced('div', 'Concentrate share = 100 / (1 + r)', dec('100').div(parts), '%w/w', [ratio.leaf]);
    const propellantShare = traced('sub', 'Propellant share = 100 - concentrate share', dec('100').minus(decOf(concentrateShare)), '%w/w', [concentrateShare]);
    const perCan = (label: string, share: Traced): Traced => traced('mul', label, fill.value.mul(decOf(share)).div(100), 'g', [fill.leaf, share]);
    const concentrateMass = perCan('Concentrate per can (g)', concentrateShare);
    const propellantMass = perCan('Propellant (gas) per can (g)', propellantShare);
    const oilMass = traced('mul', 'Fragrance oil per can (g)', decOf(concentrateMass).mul(oil.value).div(100), 'g', [concentrateMass, oil.leaf]);
    const oilPctOfProduct = traced('mul', 'Oil % of product = oil % of concentrate x concentrate share', oil.value.mul(decOf(concentrateShare)).div(100), '%w/w', [oil.leaf, concentrateShare]);
    const total = (label: string, per: Traced, n: ResolvedNumber): Traced => traced('mul', label, decOf(per).mul(n.value), 'g', [per, n.leaf]);

    const limit = resolveSourcedNumber(
      input.maxFillMassG,
      { materialId: input.fragranceId, property: 'maxFillMassG', label: 'Rated maximum net fill of the can (g)', unit: 'g', min: '0', minExclusive: true },
      input.policy,
    );
    const fillCheck: Aerosol['fillCheck'] = limit.ok
      ? (() => {
          const margin = limit.value.value.minus(fill.value);
          return {
            status: margin.gte(0) ? ('PASS' as const) : ('FAIL' as const),
            limit: limit.value.leaf,
            margin: traced('sub', 'Fill margin = rated maximum - net fill', margin, 'g', [limit.value.leaf, fill.leaf]),
            missing: [],
          };
        })()
      : { status: 'UNKNOWN', missing: limit.missing };
    const ifra = checkIfraLimit(oilPctOfProduct, input.ifraLimitPct, input.policy, input.fragranceId);

    return ok<Aerosol>(
      {
        concentrateSharePct: concentrateShare,
        propellantSharePct: propellantShare,
        concentrateMassPerCan: concentrateMass,
        propellantMassPerCan: propellantMass,
        oilMassPerCan: oilMass,
        oilPctOfProduct,
        ...(cans !== undefined && {
          batch: {
            cans: cans.leaf,
            concentrateMass: total('Concentrate for the run (g)', concentrateMass, cans),
            propellantMass: total('Propellant for the run (g)', propellantMass, cans),
          },
        }),
        fillCheck,
        ifra,
      },
      [
        AEROSOL_WARNINGS.FILLING_LINE,
        ...(limit.ok ? limit.warnings : [AEROSOL_WARNINGS.FILL_LIMIT_UNKNOWN]),
        ...ifra.warnings,
        ...(ifra.status === 'UNKNOWN' ? [FRAGRANCE_WARNINGS.IFRA_UNKNOWN] : []),
      ],
    );
  });
}
