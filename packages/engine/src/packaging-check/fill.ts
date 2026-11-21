/**
 * Container fill check (H-07). Pure, decimal.js only.
 *   usable = brimful - headspace          headspace: % of brimful capacity, or mL (explicit input, no default)
 *   PASS when nominal volume <= usable    margin = usable - nominal (mL, negative on FAIL)
 * Nominal is a volume, or a mass converted with a MEASURED product density. Brimful capacity is the
 * container spec's value (SourcedValue). Anything missing or unusable gives UNKNOWN with the gaps
 * listed, never PASS.
 */
import type { MissingData, SourcedValue } from '../contract';
import type { CheckStatus } from '../core/check-status';
import { dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import { dimensionOf, type Quantity } from '../core/quantity';
import { all, flatMap, map, missing, ok, type Result } from '../core/result';
import { INPUT_REASONS, resolveMassG, resolveSourcedNumber, resolveUserNumber, resolveVolumeMl, type ResolvedNumber } from '../sourced-input';
import { resolveDensity, type ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';

/** Required headspace (ullage): a share of the brimful capacity, or an absolute volume. */
export type Headspace = { kind: 'pct'; value: string } | { kind: 'mL'; value: string };

export interface FillCheckInput {
  containerId?: string;
  /** Nominal fill: a volume (mL, L) or a mass (g, kg; needs `productDensity`). */
  nominal: Quantity;
  /** MEASURED density of the product; required when the nominal is a mass. */
  productDensity?: SourcedValue;
  /** Brimful capacity from the container spec, mL. */
  brimfulMl: SourcedScalar | undefined;
  headspace: Headspace | undefined;
  policy: ConfidencePolicy;
}

export interface FillCheck {
  status: CheckStatus;
  nominalMl?: Traced;
  brimfulMl?: Traced;
  headspaceMl?: Traced;
  /** brimful - headspace. */
  usableMl?: Traced;
  /** usable - nominal, mL; negative on FAIL. */
  marginMl?: Traced;
  /** nominal / brimful x 100. */
  fillPctOfBrimful?: Traced;
  /** Why the status is UNKNOWN. Empty otherwise. */
  missing: MissingData[];
  warnings: string[];
}

function resolveNominalMl(input: FillCheckInput): Result<ResolvedNumber> {
  const id = input.containerId;
  const dimension = dimensionOf(input.nominal.unit);
  if (dimension === 'volume') {
    return resolveVolumeMl(id, 'nominal', 'Nominal volume (mL)', input.nominal);
  }
  if (dimension !== 'mass') {
    return missing([{ materialId: id, property: 'nominal', reason: INPUT_REASONS.INVALID_VOLUME }]);
  }
  const mass = resolveMassG(id, 'nominal', 'Nominal mass (g)', input.nominal);
  const density = resolveDensity(id, 'productDensity', input.productDensity, input.policy, 'Measured product density (g/mL)');
  return flatMap(all([mass, density] as const), ([m, d]) => {
    const ml = m.value.div(dec(d.ref.value));
    return ok({ value: ml, leaf: traced('div', 'Nominal volume = mass / density (mL)', ml, 'mL', [m.leaf, d.leaf]) });
  });
}

function resolveHeadspace(input: FillCheckInput): Result<ResolvedNumber> {
  const hs = input.headspace;
  if (hs === undefined) {
    return missing([{ materialId: input.containerId, property: 'headspace', reason: INPUT_REASONS.MISSING }]);
  }
  return hs.kind === 'pct'
    ? resolveUserNumber(hs.value, { materialId: input.containerId, property: 'headspace', label: 'Headspace (% of brimful)', unit: '%v/v', min: '0', max: '100' })
    : resolveUserNumber(hs.value, { materialId: input.containerId, property: 'headspace', label: 'Headspace (mL)', unit: 'mL', min: '0' });
}

export function checkFill(input: FillCheckInput): FillCheck {
  const inputs = all([
    resolveNominalMl(input),
    resolveSourcedNumber(
      input.brimfulMl,
      { materialId: input.containerId, property: 'brimfulMl', label: 'Brimful capacity (mL)', unit: 'mL', min: '0', minExclusive: true },
      input.policy,
    ),
    resolveHeadspace(input),
  ] as const);
  const computed = map(inputs, ([nominal, brimful, headspace]): FillCheck => {
    const headspaceMl =
      input.headspace?.kind === 'pct'
        ? traced('mul', 'Headspace (mL) = brimful x %/100', brimful.value.mul(headspace.value).div(100), 'mL', [brimful.leaf, headspace.leaf])
        : headspace.leaf;
    const usableMl = traced('sub', 'Usable volume = brimful - headspace (mL)', brimful.value.minus(decOf(headspaceMl)), 'mL', [brimful.leaf, headspaceMl]);
    const marginMl = traced('sub', 'Margin = usable - nominal (mL)', decOf(usableMl).minus(nominal.value), 'mL', [usableMl, nominal.leaf]);
    return {
      status: decOf(marginMl).gte(0) ? 'PASS' : 'FAIL',
      nominalMl: nominal.leaf,
      brimfulMl: brimful.leaf,
      headspaceMl,
      usableMl,
      marginMl,
      fillPctOfBrimful: traced('div', 'Fill = nominal / brimful (%)', nominal.value.div(brimful.value).mul(100), '%v/v', [nominal.leaf, brimful.leaf]),
      missing: [],
      warnings: [],
    };
  });
  return computed.ok
    ? { ...computed.value, warnings: computed.warnings }
    : { status: 'UNKNOWN', missing: computed.missing, warnings: computed.warnings };
}
