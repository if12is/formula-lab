/**
 * Air-care blends (L-07, L-08): reed diffuser and fragrance (burner) oil (IFRA 10A), gel, car and similar fresheners.
 * Pure, decimal.js only. Percentages are % w/w of the product:
 *   oil + sum(doses) + other + remainder = 100, the remainder (q.s.) being the carrier: solvent for a reed diffuser,
 *   water for a gel, and so on. The engine does not know which carrier a product takes; the caller names it.
 * A dose (gel agent, ...) is NEVER built in: it is a sourced value from the agent's TDS or a documented trial.
 * The IFRA hook is the shared checkIfraLimit: no sourced certificate limit, no PASS.
 */
import type { SourcedScalar } from '../core/parse';
import { dec } from '../core/decimal';
import { qty } from '../core/quantity';
import { all, combine, flatMap, missing, ok, type Result } from '../core/result';
import { resolveMassG, resolveSourcedNumber, resolveUserNumber, type ResolvedNumber } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { FRAGRANCE_REASONS, FRAGRANCE_WARNINGS, checkIfraLimit, type IfraCheck } from './fine-fragrance';

export interface BlendDose {
  /** Handle of the ingredient (gel_agent, ...); unique inside the blend. */
  key: string;
  /** % w/w of the product, sourced (TDS or a documented trial). */
  pct: SourcedScalar | undefined;
}

export interface AirCareBlendInput {
  fragranceId?: string;
  /** Fragrance oil, % w/w of the product (a typed requirement). */
  fragranceOilPct: string;
  doses?: BlendDose[];
  /** Other additives (colour, preservative, ...), % w/w. Absent means none. */
  otherPct?: string;
  batchMass: ReturnType<typeof qty>;
  ifraLimitPct?: SourcedScalar;
  policy: ConfidencePolicy;
}

export interface BlendPart {
  key: string;
  pct: Traced;
  mass: Traced;
}

export interface AirCareBlend {
  fragranceOil: BlendPart;
  doses: BlendPart[];
  /** The q.s. carrier. */
  remainder: BlendPart;
  totalMass: Traced;
  ifra: IfraCheck;
}

export function airCareBlend(input: AirCareBlendInput): Result<AirCareBlend> {
  const doses = input.doses ?? [];
  const inputs = all([
    resolveUserNumber(input.fragranceOilPct, { materialId: input.fragranceId, property: 'fragranceOilPct', label: 'Fragrance oil (%)', unit: '%w/w', min: '0', minExclusive: true, max: '100' }),
    resolveUserNumber(input.otherPct ?? '0', { materialId: input.fragranceId, property: 'otherPct', label: 'Other additives (%)', unit: '%w/w', min: '0', max: '100' }),
    combine(
      doses.map((d) =>
        resolveSourcedNumber(d.pct, { materialId: input.fragranceId, property: `${d.key}Pct`, label: `${d.key} (%)`, unit: '%w/w', min: '0', minExclusive: true, max: '100' }, input.policy),
      ),
    ),
    resolveMassG(input.fragranceId, 'batchMass', 'Batch mass M (g)', input.batchMass),
  ] as const);
  return flatMap(inputs, ([oil, other, doseValues, batch]: [ResolvedNumber, ResolvedNumber, ResolvedNumber[], ResolvedNumber]): Result<AirCareBlend> => {
    const remainder = doseValues.reduce((rest, d) => rest.minus(d.value), dec('100').minus(oil.value).minus(other.value));
    if (remainder.lte(0)) {
      return missing([{ materialId: input.fragranceId, property: 'fragranceOilPct', reason: FRAGRANCE_REASONS.PERCENTS_EXCEED_100 }]);
    }
    const M = decOf(batch.leaf);
    const part = (key: string, label: string, pct: Traced): BlendPart => ({ key, pct, mass: traced('mul', `${label} (g)`, M.mul(decOf(pct)).div(100), 'g', [batch.leaf, pct]) });
    const remainderPct = traced('sub', 'Carrier % = 100 - oil - doses - other', remainder, '%w/w', [oil.leaf, ...doseValues.map((d) => d.leaf), other.leaf]);
    const ifra = checkIfraLimit(oil.leaf, input.ifraLimitPct, input.policy, input.fragranceId);
    return ok<AirCareBlend>(
      {
        fragranceOil: part('fragrance_oil', 'Fragrance oil', oil.leaf),
        doses: doses.map((d, i) => part(d.key, d.key, (doseValues[i] as ResolvedNumber).leaf)),
        remainder: part('carrier', 'Carrier', remainderPct),
        totalMass: batch.leaf,
        ifra,
      },
      [...ifra.warnings, ...(ifra.status === 'UNKNOWN' ? [FRAGRANCE_WARNINGS.IFRA_UNKNOWN] : [])],
    );
  });
}
