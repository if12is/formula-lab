/**
 * J-01: Tier Profile type + validator. Spec: plan/02-specs/tier-profiles.md.
 *
 * A profile is a fixed set of constraints. `null` means "no constraint set yet" (J-02 fills them from
 * sources, a human task). The validator never fills or defaults a number. `requiredFieldsMissing()`
 * lists which nulls the optimizer cannot proceed without (the list is configurable per call).
 */
import { D, isDecimalString, type MissingData } from '@fl/engine';

export const TIERS = ['economy', 'mid', 'premium'] as const;
export type Tier = (typeof TIERS)[number];

export const PRICE_UNITS = ['L', 'kg', 'pack'] as const;
export type PriceUnit = (typeof PRICE_UNITS)[number];

export interface FunctionBound {
  function: string;
  /** Percent w/w of the whole formula taken by materials tagged with `function`. null = no bound yet. */
  min: string | null;
  max: string | null;
}

export interface MaterialBound {
  materialId: string;
  /** Percent w/w of the formula. null = no bound yet. */
  min: string | null;
  max: string | null;
}

export interface TierConstraints {
  totalActiveMinPct: string | null;
  totalActiveMaxPct: string | null;
  functionBounds: FunctionBound[];
  requiredMaterials: string[];
  forbiddenMaterials: string[];
  maxCostEGPperKg: string | null;
  materialBounds: MaterialBound[];
  /** Lab-measured requirements (viscosity, pH, ...). Not enforced by the LP; passed through to the output. */
  measuredRequirements: string[];
}

export interface TierProfile {
  productType: string;
  tier: Tier;
  targetRetailPrice: { per: PriceUnit; value: string | null };
  constraints: TierConstraints;
  /**
   * Source per field, required for every non-null value (CLAUDE.md rule 2). Keys: `totalActiveMinPct`,
   * `totalActiveMaxPct`, `maxCostEGPperKg`, `targetRetailPrice`, `requiredMaterials`, `forbiddenMaterials`,
   * `functionBounds.<function>`, `materialBounds.<materialId>`.
   */
  sources: Record<string, string>;
}

export interface ProfileError {
  /** JSON path, e.g. `constraints.functionBounds[1].min`. */
  path: string;
  /** Message key. */
  key: string;
  detail?: string;
}

export type ProfileValidation = { ok: true; profile: TierProfile } | { ok: false; errors: ProfileError[] };

/** JSON Schema (draft 2020-12) of the profile. The validator below is the enforcement; this is for docs and tools. */
export const TIER_PROFILE_JSON_SCHEMA = {
  $schema: 'https://json-schema.org/draft/2020-12/schema',
  title: 'TierProfile',
  type: 'object',
  additionalProperties: false,
  required: ['productType', 'tier', 'targetRetailPrice', 'constraints', 'sources'],
  $defs: {
    decimalOrNull: { oneOf: [{ type: 'null' }, { type: 'string', pattern: '^-?\\d+(\\.\\d+)?$' }] },
    idList: { type: 'array', items: { type: 'string', minLength: 1 }, uniqueItems: true },
  },
  properties: {
    productType: { type: 'string', minLength: 1 },
    tier: { enum: [...TIERS] },
    targetRetailPrice: {
      type: 'object',
      additionalProperties: false,
      required: ['per', 'value'],
      properties: { per: { enum: [...PRICE_UNITS] }, value: { $ref: '#/$defs/decimalOrNull' } },
    },
    constraints: {
      type: 'object',
      additionalProperties: false,
      required: [
        'totalActiveMinPct', 'totalActiveMaxPct', 'functionBounds', 'requiredMaterials',
        'forbiddenMaterials', 'maxCostEGPperKg', 'materialBounds', 'measuredRequirements',
      ],
      properties: {
        totalActiveMinPct: { $ref: '#/$defs/decimalOrNull' },
        totalActiveMaxPct: { $ref: '#/$defs/decimalOrNull' },
        maxCostEGPperKg: { $ref: '#/$defs/decimalOrNull' },
        functionBounds: {
          type: 'array',
          items: {
            type: 'object', additionalProperties: false, required: ['function', 'min', 'max'],
            properties: { function: { type: 'string', minLength: 1 }, min: { $ref: '#/$defs/decimalOrNull' }, max: { $ref: '#/$defs/decimalOrNull' } },
          },
        },
        materialBounds: {
          type: 'array',
          items: {
            type: 'object', additionalProperties: false, required: ['materialId', 'min', 'max'],
            properties: { materialId: { type: 'string', minLength: 1 }, min: { $ref: '#/$defs/decimalOrNull' }, max: { $ref: '#/$defs/decimalOrNull' } },
          },
        },
        requiredMaterials: { $ref: '#/$defs/idList' },
        forbiddenMaterials: { $ref: '#/$defs/idList' },
        measuredRequirements: { $ref: '#/$defs/idList' },
      },
    },
    sources: { type: 'object', additionalProperties: { type: 'string', minLength: 1 } },
  },
} as const;

type Obj = Record<string, unknown>;

function isObj(x: unknown): x is Obj {
  return typeof x === 'object' && x !== null && !Array.isArray(x);
}

class Collector {
  readonly errors: ProfileError[] = [];
  add(path: string, key: string, detail?: string): void {
    this.errors.push({ path, key, ...(detail !== undefined && { detail }) });
  }
}

function checkKeys(c: Collector, path: string, o: Obj, allowed: readonly string[]): void {
  for (const k of Object.keys(o)) {
    if (!allowed.includes(k)) {
      c.add(path === '' ? k : `${path}.${k}`, 'optimizer.profile.unknown_field');
    }
  }
  for (const k of allowed) {
    if (!(k in o)) {
      c.add(path === '' ? k : `${path}.${k}`, 'optimizer.profile.field_required');
    }
  }
}

/** string | null decimal; returns the string, or null when absent/invalid/null. */
function decimalOrNull(c: Collector, path: string, x: unknown, range: { min: string; max?: string }): string | null {
  if (x === null || x === undefined) {
    return null;
  }
  if (!isDecimalString(x)) {
    c.add(path, 'optimizer.profile.not_decimal_string');
    return null;
  }
  const v = new D(x);
  if (v.lt(range.min) || (range.max !== undefined && v.gt(range.max))) {
    c.add(path, 'optimizer.profile.out_of_range');
    return null;
  }
  return x;
}

function idList(c: Collector, path: string, x: unknown): string[] {
  if (!Array.isArray(x)) {
    if (x !== undefined) {
      c.add(path, 'optimizer.profile.not_array');
    }
    return [];
  }
  const out: string[] = [];
  x.forEach((v, i) => {
    if (typeof v !== 'string' || v === '') {
      c.add(`${path}[${i}]`, 'optimizer.profile.not_id');
    } else if (out.includes(v)) {
      c.add(`${path}[${i}]`, 'optimizer.profile.duplicate', v);
    } else {
      out.push(v);
    }
  });
  return out;
}

function bounds<K extends 'function' | 'materialId'>(
  c: Collector, path: string, x: unknown, idKey: K,
): ({ [P in K]: string } & { min: string | null; max: string | null })[] {
  if (!Array.isArray(x)) {
    if (x !== undefined) {
      c.add(path, 'optimizer.profile.not_array');
    }
    return [];
  }
  const out: ({ [P in K]: string } & { min: string | null; max: string | null })[] = [];
  const seen = new Set<string>();
  x.forEach((raw, i) => {
    const p = `${path}[${i}]`;
    if (!isObj(raw)) {
      c.add(p, 'optimizer.profile.not_object');
      return;
    }
    checkKeys(c, p, raw, [idKey, 'min', 'max']);
    const id = raw[idKey];
    if (typeof id !== 'string' || id === '') {
      c.add(`${p}.${idKey}`, 'optimizer.profile.not_id');
      return;
    }
    if (seen.has(id)) {
      c.add(`${p}.${idKey}`, 'optimizer.profile.duplicate', id);
    }
    seen.add(id);
    const min = decimalOrNull(c, `${p}.min`, raw.min, { min: '0', max: '100' });
    const max = decimalOrNull(c, `${p}.max`, raw.max, { min: '0', max: '100' });
    if (min !== null && max !== null && new D(min).gt(max)) {
      c.add(p, 'optimizer.profile.min_exceeds_max');
    }
    out.push({ [idKey]: id, min, max } as { [P in K]: string } & { min: string | null; max: string | null });
  });
  return out;
}

/** Validates unknown JSON as a TierProfile. Strict: unknown keys, JS numbers and unsourced values are errors. */
export function validateTierProfile(raw: unknown): ProfileValidation {
  const c = new Collector();
  if (!isObj(raw)) {
    return { ok: false, errors: [{ path: '', key: 'optimizer.profile.not_object' }] };
  }
  checkKeys(c, '', raw, ['productType', 'tier', 'targetRetailPrice', 'constraints', 'sources']);

  const productType = typeof raw.productType === 'string' && raw.productType !== '' ? raw.productType : null;
  if (productType === null && 'productType' in raw) {
    c.add('productType', 'optimizer.profile.not_id');
  }
  const tier = TIERS.find((t) => t === raw.tier);
  if (tier === undefined && 'tier' in raw) {
    c.add('tier', 'optimizer.profile.invalid_tier');
  }

  let per: PriceUnit | undefined;
  let retail: string | null = null;
  if (isObj(raw.targetRetailPrice)) {
    checkKeys(c, 'targetRetailPrice', raw.targetRetailPrice, ['per', 'value']);
    per = PRICE_UNITS.find((u) => u === (raw.targetRetailPrice as Obj).per);
    if (per === undefined && 'per' in raw.targetRetailPrice) {
      c.add('targetRetailPrice.per', 'optimizer.profile.invalid_price_unit');
    }
    retail = decimalOrNull(c, 'targetRetailPrice.value', raw.targetRetailPrice.value, { min: '0' });
    if (retail !== null && new D(retail).isZero()) {
      c.add('targetRetailPrice.value', 'optimizer.profile.out_of_range');
    }
  } else if ('targetRetailPrice' in raw) {
    c.add('targetRetailPrice', 'optimizer.profile.not_object');
  }

  let constraints: TierConstraints | undefined;
  if (isObj(raw.constraints)) {
    const k = raw.constraints;
    checkKeys(c, 'constraints', k, [
      'totalActiveMinPct', 'totalActiveMaxPct', 'functionBounds', 'requiredMaterials',
      'forbiddenMaterials', 'maxCostEGPperKg', 'materialBounds', 'measuredRequirements',
    ]);
    const totalActiveMinPct = decimalOrNull(c, 'constraints.totalActiveMinPct', k.totalActiveMinPct, { min: '0', max: '100' });
    const totalActiveMaxPct = decimalOrNull(c, 'constraints.totalActiveMaxPct', k.totalActiveMaxPct, { min: '0', max: '100' });
    if (totalActiveMinPct !== null && totalActiveMaxPct !== null && new D(totalActiveMinPct).gt(totalActiveMaxPct)) {
      c.add('constraints.totalActiveMinPct', 'optimizer.profile.min_exceeds_max');
    }
    const requiredMaterials = idList(c, 'constraints.requiredMaterials', k.requiredMaterials);
    const forbiddenMaterials = idList(c, 'constraints.forbiddenMaterials', k.forbiddenMaterials);
    for (const id of requiredMaterials) {
      if (forbiddenMaterials.includes(id)) {
        c.add('constraints.requiredMaterials', 'optimizer.profile.required_and_forbidden', id);
      }
    }
    constraints = {
      totalActiveMinPct,
      totalActiveMaxPct,
      functionBounds: bounds(c, 'constraints.functionBounds', k.functionBounds, 'function'),
      requiredMaterials,
      forbiddenMaterials,
      maxCostEGPperKg: decimalOrNull(c, 'constraints.maxCostEGPperKg', k.maxCostEGPperKg, { min: '0' }),
      materialBounds: bounds(c, 'constraints.materialBounds', k.materialBounds, 'materialId'),
      measuredRequirements: idList(c, 'constraints.measuredRequirements', k.measuredRequirements),
    };
  } else if ('constraints' in raw) {
    c.add('constraints', 'optimizer.profile.not_object');
  }

  const sources: Record<string, string> = {};
  if (isObj(raw.sources)) {
    for (const [key, v] of Object.entries(raw.sources)) {
      if (typeof v !== 'string' || v === '') {
        c.add(`sources.${key}`, 'optimizer.profile.not_id');
      } else {
        sources[key] = v;
      }
    }
  } else if ('sources' in raw) {
    c.add('sources', 'optimizer.profile.not_object');
  }

  if (constraints !== undefined && productType !== null && tier !== undefined && per !== undefined) {
    const profile: TierProfile = { productType, tier, targetRetailPrice: { per, value: retail }, constraints, sources };
    for (const field of sourcedFieldsOf(profile)) {
      if (sources[field] === undefined) {
        c.add(`sources.${field}`, 'optimizer.profile.source_required');
      }
    }
    return c.errors.length === 0 ? { ok: true, profile } : { ok: false, errors: c.errors };
  }
  return { ok: false, errors: c.errors };
}

/** Every field that currently holds a value and therefore needs an entry in `sources`. */
export function sourcedFieldsOf(p: TierProfile): string[] {
  const k = p.constraints;
  const out: string[] = [];
  if (k.totalActiveMinPct !== null) out.push('totalActiveMinPct');
  if (k.totalActiveMaxPct !== null) out.push('totalActiveMaxPct');
  if (k.maxCostEGPperKg !== null) out.push('maxCostEGPperKg');
  if (p.targetRetailPrice.value !== null) out.push('targetRetailPrice');
  if (k.requiredMaterials.length > 0) out.push('requiredMaterials');
  if (k.forbiddenMaterials.length > 0) out.push('forbiddenMaterials');
  for (const f of k.functionBounds) {
    if (f.min !== null || f.max !== null) out.push(`functionBounds.${f.function}`);
  }
  for (const m of k.materialBounds) {
    if (m.min !== null || m.max !== null) out.push(`materialBounds.${m.materialId}`);
  }
  return out;
}

/**
 * Which required fields are still null. Field names: `totalActiveMinPct`, `totalActiveMaxPct`,
 * `maxCostEGPperKg`, `targetRetailPrice`, `functionBounds.<function>.min|max`. The result is `missing`
 * data with property `profile.<field>`; the optimizer does not solve while this list is non-empty.
 */
export function requiredFieldsMissing(p: TierProfile, requiredFields: readonly string[]): MissingData[] {
  const gap = (field: string, reason: string): MissingData => ({ property: `profile.${field}`, reason });
  const out: MissingData[] = [];
  for (const field of requiredFields) {
    const fb = /^functionBounds\.(.+)\.(min|max)$/.exec(field);
    if (fb !== null) {
      const bound = p.constraints.functionBounds.find((b) => b.function === fb[1]);
      if (bound === undefined) {
        out.push(gap(field, 'optimizer.profile.function_not_declared'));
      } else if (bound[fb[2] as 'min' | 'max'] === null) {
        out.push(gap(field, 'optimizer.profile.field_null_required'));
      }
      continue;
    }
    const value: string | null | undefined =
      field === 'targetRetailPrice'
        ? p.targetRetailPrice.value
        : field === 'totalActiveMinPct' || field === 'totalActiveMaxPct' || field === 'maxCostEGPperKg'
          ? p.constraints[field]
          : undefined;
    if (value === undefined) {
      out.push(gap(field, 'optimizer.profile.unknown_required_field'));
    } else if (value === null) {
      out.push(gap(field, 'optimizer.profile.field_null_required'));
    }
  }
  return out;
}
