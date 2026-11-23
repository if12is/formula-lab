/**
 * Candle and wax melt fragrance load (L-07, IFRA category 12). Pure, decimal.js only.
 *   load is % of the WAX weight: fragrance mass = wax mass x load / 100, finished mass = wax + fragrance,
 *   fragrance % of the finished candle = load / (100 + load) x 100 (the figure an IFRA limit is written against).
 * The wax's maximum load comes from the wax TDS as a sourced value: there is no built-in limit, and with none the load check is
 * UNKNOWN (never PASS). The IFRA hook is the shared checkIfraLimit on the finished-candle %.
 */
import type { MissingData } from '../contract';
import type { CheckStatus } from '../core/check-status';
import type { SourcedScalar } from '../core/parse';
import { qty } from '../core/quantity';
import { all, flatMap, ok, type Result } from '../core/result';
import { resolveMassG, resolveSourcedNumber, resolveUserNumber } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { FRAGRANCE_WARNINGS, checkIfraLimit, type IfraCheck } from './fine-fragrance';

export const CANDLE_WARNINGS = {
  WAX_LIMIT_UNKNOWN: 'fragrance.candle_wax_limit_unknown',
} as const;

export interface CandleInput {
  fragranceId?: string;
  /** Fragrance load, % of the wax weight (a typed requirement). */
  loadPctOfWax: string;
  waxMass: ReturnType<typeof qty>;
  /** Maximum fragrance load of the wax, % of its weight, from the wax TDS. Absent leaves the load check UNKNOWN. */
  waxMaxLoadPct?: SourcedScalar;
  ifraLimitPct?: SourcedScalar;
  policy: ConfidencePolicy;
}

export interface Candle {
  fragranceMass: Traced;
  finishedMass: Traced;
  /** Fragrance % of the finished candle. */
  fragrancePctOfCandle: Traced;
  loadCheck: { status: CheckStatus; limit?: Traced; margin?: Traced; missing: MissingData[] };
  ifra: IfraCheck;
}

export function candle(input: CandleInput): Result<Candle> {
  const inputs = all([
    resolveUserNumber(input.loadPctOfWax, { materialId: input.fragranceId, property: 'loadPctOfWax', label: 'Fragrance load (% of wax)', unit: '%w/w', min: '0', minExclusive: true }),
    resolveMassG(input.fragranceId, 'waxMass', 'Wax mass (g)', input.waxMass),
  ] as const);
  return flatMap(inputs, ([load, wax]): Result<Candle> => {
    const fragranceMass = traced('mul', 'Fragrance (g) = wax x load / 100', wax.value.mul(load.value).div(100), 'g', [wax.leaf, load.leaf]);
    const finishedMass = traced('add', 'Finished mass (g) = wax + fragrance', wax.value.plus(decOf(fragranceMass)), 'g', [wax.leaf, fragranceMass]);
    const pctOfCandle = traced('div', 'Fragrance % of the candle = load / (100 + load)', load.value.mul(100).div(load.value.plus(100)), '%w/w', [load.leaf]);
    const limit = resolveSourcedNumber(
      input.waxMaxLoadPct,
      { materialId: input.fragranceId, property: 'waxMaxLoadPct', label: 'Maximum fragrance load of the wax (% of wax)', unit: '%w/w', min: '0', minExclusive: true },
      input.policy,
    );
    const loadCheck: Candle['loadCheck'] = limit.ok
      ? (() => {
          const margin = limit.value.value.minus(load.value);
          return {
            status: margin.gte(0) ? ('PASS' as const) : ('FAIL' as const),
            limit: limit.value.leaf,
            margin: traced('sub', 'Load margin = wax maximum - load', margin, '%w/w', [limit.value.leaf, load.leaf]),
            missing: [],
          };
        })()
      : { status: 'UNKNOWN', missing: limit.missing };
    const ifra = checkIfraLimit(pctOfCandle, input.ifraLimitPct, input.policy, input.fragranceId);
    return ok<Candle>(
      { fragranceMass, finishedMass, fragrancePctOfCandle: pctOfCandle, loadCheck, ifra },
      [
        ...(limit.ok ? limit.warnings : [CANDLE_WARNINGS.WAX_LIMIT_UNKNOWN]),
        ...ifra.warnings,
        ...(ifra.status === 'UNKNOWN' ? [FRAGRANCE_WARNINGS.IFRA_UNKNOWN] : []),
      ],
    );
  });
}
