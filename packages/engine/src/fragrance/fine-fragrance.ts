/**
 * Fine fragrance batch calculator (L-02). Pure, decimal.js only.
 * Percentages are % w/w of the finished product:
 *   oil + fixative + added water + carrier = 100, carrier = ethanol-water at the target %v/v.
 * The carrier is made from COA ethanol by the OIML R 22 mass balance (alcohol-dilution.ts), so
 * alcohol stock and dilution water are masses, never volume differences.
 * Final ethanol %v/v of the whole product is an INDICATOR only: it applies the OIML table (a
 * binary ethanol-water table) to the ethanol mass fraction of a mix that also holds oil, which is
 * not water. The warning key `fragrance.final_ethanol_indicator_only` is always emitted.
 * The IFRA hook never says PASS without a sourced limit from the fragrance's IFRA certificate.
 */
import type { MissingData, SourcedValue } from '../contract';
import { vvFromWW, type OimlTable } from '../core/alcohol';
import type { CheckStatus } from '../core/check-status';
import { dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import { all, flatMap, map, missing, ok, type Result } from '../core/result';
import { resolveMassG, resolveSourcedNumber, resolveUserNumber, resolveVolumeMl, type ResolvedNumber } from '../sourced-input';
import { resolveDensity, type ConfidencePolicy, type ResolvedDensity } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { ethanolMassBalance, resolveEthanolSource, resolveTargetPctVV, type BatchTarget } from './alcohol-dilution';

export const FRAGRANCE_REASONS = {
  PERCENTS_EXCEED_100: 'fragrance.percents_exceed_100',
} as const;

export const FRAGRANCE_WARNINGS = {
  FINAL_ETHANOL_INDICATOR_ONLY: 'fragrance.final_ethanol_indicator_only',
  IFRA_UNKNOWN: 'fragrance.ifra_unknown',
} as const;

export interface IfraCheck {
  status: CheckStatus;
  /** Limit from the IFRA certificate for the product category, % w/w of the finished product. */
  limitPct?: Traced;
  /** limit - oil %; negative on FAIL. */
  marginPct?: Traced;
  /** Why the status is UNKNOWN. Empty otherwise. */
  missing: MissingData[];
  warnings: string[];
}

/** fragranceOilPct <= IFRA limit. A missing/invalid/L7 limit gives UNKNOWN, never PASS. */
export function checkIfraLimit(
  oilPct: Traced,
  limit: SourcedScalar | undefined,
  policy: ConfidencePolicy,
  fragranceId?: string,
): IfraCheck {
  const resolved = resolveSourcedNumber(
    limit,
    { materialId: fragranceId, property: 'ifraLimitPct', label: 'IFRA limit for the product category (%)', unit: '%w/w', min: '0', max: '100' },
    policy,
  );
  if (!resolved.ok) {
    return { status: 'UNKNOWN', missing: resolved.missing, warnings: resolved.warnings };
  }
  const margin = resolved.value.value.minus(decOf(oilPct));
  return {
    status: margin.gte(0) ? 'PASS' : 'FAIL',
    limitPct: resolved.value.leaf,
    marginPct: traced('sub', 'IFRA margin = limit - oil %', margin, '%w/w', [resolved.value.leaf, oilPct]),
    missing: [],
    warnings: resolved.warnings,
  };
}

export interface FineFragranceInput {
  fragranceId?: string;
  /** Fragrance oil, % w/w of the product (a typed requirement; Parfum/EdP/EdT names are trade custom). */
  fragranceOilPct: string;
  /** Optional fixative / colour, % w/w. Absent means none. */
  fixativePct?: string;
  /** Optional extra water on top of the carrier's own, % w/w. Absent means none. */
  addedWaterPct?: string;
  /** COA ethanol strength; its unit ('%v/v' or '%w/w') is the stated basis. */
  alcoholSource: SourcedScalar | undefined;
  alcoholMaterialId?: string;
  /** Strength of the ethanol-water carrier, %v/v. */
  alcoholTargetPctVV: string;
  batch: BatchTarget;
  /** MEASURED density of the finished product: required for a volume batch, optional (adds the volume) for a mass batch. */
  productDensity?: SourcedValue;
  /** Limit from the fragrance's IFRA certificate for the product category. */
  ifraLimitPct?: SourcedScalar;
  policy: ConfidencePolicy;
  /** Test seam: defaults to the shipped OIML R 22 table. */
  table?: OimlTable;
}

export interface FineFragrance {
  fragranceOilMass: Traced;
  fixativeMass?: Traced;
  alcoholMass: Traced;
  /** Dilution water of the carrier plus any added water. */
  waterMass: Traced;
  totalMass: Traced;
  totalVolume?: Traced;
  carrierPctWW: Traced;
  finalEthanolPctWW: Traced;
  /** Indicator only (see module header). */
  finalEthanolPctVV: Traced;
  ifra: IfraCheck;
}

interface BatchTotals {
  totalMass: Traced;
  totalVolume?: Traced;
}

function resolveBatch(input: FineFragranceInput): Result<BatchTotals> {
  const byVolume = 'volume' in input.batch;
  const given = 'mass' in input.batch
    ? resolveMassG(undefined, 'batchMass', 'Batch mass M (g)', input.batch.mass)
    : resolveVolumeMl(undefined, 'batchVolume', 'Batch volume V (mL)', input.batch.volume);
  const needsDensity = byVolume || input.productDensity !== undefined;
  const density: Result<ResolvedDensity | undefined> = needsDensity
    ? resolveDensity(undefined, 'productDensity', input.productDensity, input.policy, 'Measured product density (g/mL)')
    : ok(undefined);
  return flatMap(all([given, density] as const), ([g, d]): Result<BatchTotals> => {
    if (d === undefined) {
      return ok({ totalMass: g.leaf });
    }
    const rho = dec(d.ref.value);
    return ok(
      byVolume
        ? { totalMass: traced('mul', 'M = V x rho (g)', g.value.mul(rho), 'g', [g.leaf, d.leaf]), totalVolume: g.leaf }
        : { totalMass: g.leaf, totalVolume: traced('div', 'V = M / rho (mL)', g.value.div(rho), 'mL', [g.leaf, d.leaf]) },
    );
  });
}

const pctSpec = (property: string, label: string, positive: boolean) =>
  ({ property, label, unit: '%w/w', min: '0', minExclusive: positive, max: '100' }) as const;

/** An optional percentage: absent means 0 (the ingredient is not used). */
function optionalPct(value: string | undefined, property: string, label: string): Result<ResolvedNumber> {
  return resolveUserNumber(value ?? '0', pctSpec(property, label, false));
}

export function fineFragrance(input: FineFragranceInput): Result<FineFragrance> {
  const inputs = all([
    resolveUserNumber(input.fragranceOilPct, pctSpec('fragranceOilPct', 'Fragrance oil (%)', true)),
    optionalPct(input.fixativePct, 'fixativePct', 'Fixative (%)'),
    optionalPct(input.addedWaterPct, 'addedWaterPct', 'Added water (%)'),
    resolveEthanolSource(input.alcoholSource, { materialId: input.alcoholMaterialId, property: 'alcoholSource', label: 'Source ethanol strength' }, input.policy, input.table),
    resolveTargetPctVV(input.alcoholTargetPctVV, { property: 'alcoholTargetPctVV', label: 'Carrier ethanol strength (%v/v)' }, input.table),
    resolveBatch(input),
  ] as const);
  return flatMap(inputs, ([oil, fixative, water, source, target, batch]): Result<FineFragrance> => {
    const carrier = dec('100').minus(oil.value).minus(fixative.value).minus(water.value);
    if (carrier.lte(0)) {
      return missing([{ materialId: input.fragranceId, property: 'fragranceOilPct', reason: FRAGRANCE_REASONS.PERCENTS_EXCEED_100 }]);
    }
    const { totalMass, totalVolume } = batch;
    const M = decOf(totalMass);
    const carrierPct = traced('sub', 'Carrier % = 100 - oil - fixative - added water', carrier, '%w/w', [oil.leaf, fixative.leaf, water.leaf]);
    const part = (label: string, pct: Traced): Traced => traced('mul', label, M.mul(decOf(pct)).div(100), 'g', [totalMass, pct]);
    const carrierMass = part('Carrier mass (g)', carrierPct);
    const addedWater = part('Added water (g)', water.leaf);
    const ethanolWW = traced('mul', 'Ethanol % w/w of the product = carrier % x w_T', carrier.mul(decOf(target.pctWW)).div(100), '%w/w', [carrierPct, target.pctWW]);
    const finalVV = map(vvFromWW(decOf(ethanolWW), input.table), (vv) =>
      traced('oiml', 'Final ethanol %v/v (indicator: OIML table applied to a mix that holds oil)', vv, '%v/v', [ethanolWW]),
    );
    return flatMap(all([ethanolMassBalance(source, target, carrierMass, input.alcoholMaterialId), finalVV] as const), ([balance, finalEthanolPctVV]) => {
      const ifra = checkIfraLimit(oil.leaf, input.ifraLimitPct, input.policy, input.fragranceId);
      return ok<FineFragrance>(
        {
          fragranceOilMass: part('Fragrance oil (g)', oil.leaf),
          ...(input.fixativePct !== undefined && { fixativeMass: part('Fixative (g)', fixative.leaf) }),
          alcoholMass: balance.sourceMass,
          waterMass: traced('add', 'Water (g) = dilution water + added water', decOf(balance.waterMass).plus(decOf(addedWater)), 'g', [balance.waterMass, addedWater]),
          totalMass,
          ...(totalVolume !== undefined && { totalVolume }),
          carrierPctWW: carrierPct,
          finalEthanolPctWW: ethanolWW,
          finalEthanolPctVV,
          ifra,
        },
        [
          FRAGRANCE_WARNINGS.FINAL_ETHANOL_INDICATOR_ONLY,
          ...ifra.warnings,
          ...(ifra.status === 'UNKNOWN' ? [FRAGRANCE_WARNINGS.IFRA_UNKNOWN] : []),
        ],
      );
    });
  });
}
