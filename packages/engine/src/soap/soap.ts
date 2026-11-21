/**
 * Saponification lye (F-06). Pure, decimal.js only.
 *   Lye = (sum(m_i x SAP_i)) x (1 - SF/100) / purity
 * SAP is an INPUT per oil, in an explicit unit: mg KOH per g of oil, or g NaOH per g of oil.
 * KOH-based values convert with /1000 (and x M_NaOH/M_KOH for NaOH); NaOH-based values convert
 * to KOH by the inverse ratio. SAP may come as {min, typ, max} (reference ranges) plus an optional
 * lot value (COA); the lot value takes precedence over typ for the "used" figure.
 * No oil table is embedded: SAP data comes from the caller.
 */
import { dec, type Dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import type { Quantity } from '../core/quantity';
import { all, missing, ok, type Result } from '../core/result';
import { baseEquivalentPerKoh, type StrongBase } from '../base-equivalent';
import { resolveMassG, resolveSourcedNumber, resolveUserNumber, type ResolvedNumber } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { waterForLye, type WaterPlan, type WaterSpec } from './water';

export const SOAP_REASONS = {
  NO_OILS: 'soap.no_oils',
  SAP_RANGE_INCONSISTENT: 'soap.sap_range_inconsistent',
} as const;

export const SOAP_WARNINGS = {
  SAP_RANGE_UNAVAILABLE: 'soap.sap_range_unavailable',
} as const;

export type SapUnit = 'mgKOH/g' | 'gNaOH/g';

export interface SapSpec {
  /** Unit of every value in this spec. */
  unit: SapUnit;
  typ: SourcedScalar;
  min?: SourcedScalar;
  max?: SourcedScalar;
  /** SAP measured on the lot (COA). Preferred over typ for the "used" figure. */
  lot?: SourcedScalar;
}

export interface SaponifiableOil {
  oilId: string;
  mass: Quantity;
  sap: SapSpec;
}

export interface SoapInput {
  oils: readonly SaponifiableOil[];
  base: StrongBase;
  /** Purity of the base from the lot COA, % w/w. No default: missing purity is reported. */
  purity: SourcedScalar;
  baseId?: string;
  /** Superfat %, 0 <= SF < 100 (a formulation choice typed by the user). */
  superfatPct: string;
  water?: WaterSpec;
  policy: ConfidencePolicy;
}

export interface SpreadOfSap {
  /** max - min lye (g). */
  absolute: Traced;
  /** (max - min) / typ x 100. */
  pctOfTyp: Traced;
}

export interface SoapLye {
  base: StrongBase;
  /** Lye with the best available SAP per oil (lot over typ). */
  used: Traced;
  /** Per oil, which value fed `used`. */
  usedBasis: Record<string, 'lot' | 'typ'>;
  typ: Traced;
  /** Present only when EVERY oil has a min (resp. max). */
  min?: Traced;
  max?: Traced;
  spread?: SpreadOfSap;
  oilsMass: Traced;
  water?: WaterPlan;
}

type Scenario = 'used' | 'typ' | 'min' | 'max';

interface OilSap {
  oilId: string;
  mass: ResolvedNumber;
  /** Lye grams per gram of oil per scenario; absent when the scenario has no value for this oil. */
  perGram: Partial<Record<Scenario, ResolvedNumber & { grams: Dec }>>;
  basis: 'lot' | 'typ';
}

/** SAP value -> grams of `base` per gram of oil, with a conversion node. */
function lyePerGram(value: ResolvedNumber, unit: SapUnit, base: StrongBase): { grams: Dec; node: Traced } {
  if (unit === 'mgKOH/g') {
    const f = baseEquivalentPerKoh(base);
    const grams = value.value.div(1000).mul(decOf(f));
    return { grams, node: traced('mul', `SAP as ${base} (g/g) = SAP/1000 x f`, grams, 'g', [value.leaf, f]) };
  }
  if (base === 'NaOH') {
    return { grams: value.value, node: value.leaf };
  }
  const f = baseEquivalentPerKoh('NaOH');
  const grams = value.value.div(decOf(f));
  return { grams, node: traced('div', 'SAP as KOH (g/g) = SAP / (M_NaOH/M_KOH)', grams, 'g', [value.leaf, f]) };
}

function resolveOil(oil: SaponifiableOil, base: StrongBase, policy: ConfidencePolicy): Result<OilSap> {
  const id = oil.oilId;
  const spec = (name: string, label: string) => ({
    materialId: id,
    property: `sap.${name}`,
    label: `${id} SAP ${label} (${oil.sap.unit})`,
    unit: oil.sap.unit === 'mgKOH/g' ? ('mgKOH/g' as const) : ('g' as const),
    min: '0',
    minExclusive: true,
  });
  const optional = (name: 'min' | 'max' | 'lot'): Result<ResolvedNumber | undefined> => {
    const sv = oil.sap[name];
    return sv === undefined ? ok(undefined) : resolveSourcedNumber(sv, spec(name, name), policy);
  };
  const parts = all([
    resolveMassG(id, 'mass', `${id} mass (g)`, oil.mass),
    resolveSourcedNumber(oil.sap.typ, spec('typ', 'typ'), policy),
    optional('min'),
    optional('max'),
    optional('lot'),
  ] as const);
  if (!parts.ok) {
    return parts;
  }
  const [mass, typ, min, max, lot] = parts.value;
  const outOfOrder = (min !== undefined && min.value.gt(typ.value)) || (max !== undefined && max.value.lt(typ.value));
  if (outOfOrder) {
    return missing([{ materialId: id, property: 'sap.range', reason: SOAP_REASONS.SAP_RANGE_INCONSISTENT }], parts.warnings);
  }
  const perGram: OilSap['perGram'] = {};
  const put = (s: Scenario, v: ResolvedNumber | undefined) => {
    if (v !== undefined) {
      const c = lyePerGram(v, oil.sap.unit, base);
      perGram[s] = { value: v.value, leaf: c.node, grams: c.grams };
    }
  };
  put('typ', typ);
  put('min', min);
  put('max', max);
  put('used', lot ?? typ);
  return ok({ oilId: id, mass, perGram, basis: lot === undefined ? 'typ' : 'lot' }, parts.warnings);
}

/** Σ m x SAP -> x (1 - SF/100) -> / purity, as one traced lye mass; undefined when some oil lacks the scenario. */
function lyeFor(scenario: Scenario, oils: readonly OilSap[], keep: Traced, purity: ResolvedNumber): Traced | undefined {
  const terms: Traced[] = [];
  let sum = dec('0');
  for (const o of oils) {
    const p = o.perGram[scenario];
    if (p === undefined) {
      return undefined;
    }
    const t = o.mass.value.mul(p.grams);
    sum = sum.plus(t);
    terms.push(traced('mul', `${o.oilId} lye at SAP ${scenario} = m x SAP`, t, 'g', [o.mass.leaf, p.leaf]));
  }
  const sapSum = traced('sum', `Σ m x SAP (${scenario})`, sum, 'g', terms);
  const afterSf = traced('mul', 'x (1 - SF/100)', sum.mul(decOf(keep)), 'g', [sapSum, keep]);
  return traced('div', `Lye (${scenario}) = Σ m x SAP x (1 - SF/100) / purity`, sum.mul(decOf(keep)).div(purity.value).mul(100), 'g', [afterSf, purity.leaf]);
}

export function soapLye(input: SoapInput): Result<SoapLye> {
  if (input.oils.length === 0) {
    return missing([{ property: 'oils', reason: SOAP_REASONS.NO_OILS }]);
  }
  const base = input.base;
  const inputs = all([
    all(input.oils.map((o) => resolveOil(o, base, input.policy))),
    resolveSourcedNumber(
      input.purity,
      { materialId: input.baseId, property: 'purity', label: `${input.baseId ?? base} purity (%)`, unit: '%w/w', min: '0', minExclusive: true, max: '100' },
      input.policy,
    ),
    resolveUserNumber(input.superfatPct, { property: 'superfatPct', label: 'Superfat (%)', unit: '%w/w', min: '0', max: '100' }),
  ] as const);
  if (!inputs.ok) {
    return inputs;
  }
  const [oils, purity, sf] = inputs.value;
  if (sf.value.gte(100)) {
    return missing([{ property: 'superfatPct', reason: 'input.out_of_range' }], inputs.warnings);
  }
  const keep = traced('sub', '1 - SF/100', dec('1').minus(sf.value.div(100)), '%w/w', [sf.leaf]);
  const lye = (s: Scenario) => lyeFor(s, oils, keep, purity);
  const used = lye('used') as Traced;
  const typ = lye('typ') as Traced;
  const min = lye('min');
  const max = lye('max');
  const warnings = [...inputs.warnings];
  let spread: SpreadOfSap | undefined;
  if (min === undefined || max === undefined) {
    const lacking = oils.filter((o) => o.perGram.min === undefined || o.perGram.max === undefined).map((o) => o.oilId);
    warnings.push(`${SOAP_WARNINGS.SAP_RANGE_UNAVAILABLE}:${lacking.join(',')}`);
  } else {
    const abs = decOf(max).minus(decOf(min));
    const absT = traced('sub', 'Lye spread = max - min', abs, 'g', [max, min]);
    spread = { absolute: absT, pctOfTyp: traced('div', 'Spread as % of typ', abs.div(decOf(typ)).mul(100), '%w/w', [absT, typ]) };
  }
  const oilsMass = traced('sum', 'Total oils (g)', oils.reduce((s, o) => s.plus(o.mass.value), dec('0')), 'g', oils.map((o) => o.mass.leaf));
  const result: SoapLye = {
    base,
    used,
    usedBasis: Object.fromEntries(oils.map((o) => [o.oilId, o.basis])),
    typ,
    ...(min && { min }),
    ...(max && { max }),
    ...(spread && { spread }),
    oilsMass,
  };
  if (input.water !== undefined) {
    const water = waterForLye(used, oilsMass, input.water);
    if (!water.ok) {
      return { ...water, warnings: [...warnings, ...water.warnings] };
    }
    result.water = water.value;
  }
  return ok(result, warnings);
}
