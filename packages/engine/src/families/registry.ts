/**
 * The product family registry (K-01). Pure validation of the data file (packages/engine/data/product-families.json):
 * every vocabulary reference must resolve, nothing may be guessed (a regulator, IFRA category or tax regime the sources do not
 * state stays null), and a row may only be called verified when its source is better than an estimate.
 * Failures come back as a complete `missing[]`, never a throw and never a silent default.
 */
import type { MissingData } from '../contract';
import { missing, ok, type Result } from '../core/result';
import type { ProductFamily, ProductFamilyRegistry } from './types';

export const FAMILY_REASONS = {
  NOT_OBJECT: 'families.not_object',
  NO_FAMILIES: 'families.no_families',
  CODE_INVALID: 'families.code_invalid',
  CODE_DUPLICATE: 'families.code_duplicate',
  NAME_MISSING: 'families.name_missing',
  GROUP_UNKNOWN: 'families.group_unknown',
  REGULATOR_UNKNOWN: 'families.regulator_unknown',
  IFRA_INVALID: 'families.ifra_invalid',
  CALCULATION_UNKNOWN: 'families.calculation_unknown',
  PROPERTY_UNKNOWN: 'families.property_unknown',
  LIST_DUPLICATE: 'families.list_duplicate',
  SOURCE_UNKNOWN: 'families.source_unknown',
  CONFIDENCE_INVALID: 'families.confidence_invalid',
  VERIFICATION_INVALID: 'families.verification_invalid',
  VERIFIED_NEEDS_SOURCE: 'families.verified_needs_source',
  VOCABULARY_INVALID: 'families.vocabulary_invalid',
} as const;

export const CONFIDENCE_LEVELS = [
  'L1_coa_batch',
  'L2_supplier_tds',
  'L3_standard',
  'L4_regulatory_db',
  'L5_reference',
  'L6_community',
  'L7_estimate',
] as const;

const CODE = /^[a-z][a-z0-9_]*$/;
/** IFRA categories look like 4, 5A, 10B. The pattern checks the shape only; which category applies is data. */
const IFRA = /^[0-9]{1,2}[A-Z]?$/;

const isRecord = (x: unknown): x is Record<string, unknown> => typeof x === 'object' && x !== null && !Array.isArray(x);
const isText = (x: unknown): x is string => typeof x === 'string' && x.trim() !== '';

export function localizedNameIssue(name: unknown): boolean {
  return !(isRecord(name) && isText(name.ar) && isText(name.en));
}

function duplicates(list: readonly string[]): string[] {
  return list.filter((id, index) => list.indexOf(id) !== index);
}

export function validateProductFamilyRegistry(input: unknown): Result<ProductFamilyRegistry> {
  if (!isRecord(input) || !isRecord(input.vocabulary) || !Array.isArray(input.sources)) {
    return missing([{ property: '$', reason: FAMILY_REASONS.NOT_OBJECT }]);
  }
  const gaps: MissingData[] = [];
  const v = input.vocabulary;
  const calculations = Array.isArray(v.calculations) ? (v.calculations as Array<Record<string, unknown>>) : [];
  const properties = Array.isArray(v.measuredProperties) ? (v.measuredProperties as Array<Record<string, unknown>>) : [];
  const regulators = Array.isArray(v.regulators) ? (v.regulators as unknown[]) : [];
  const groups = Array.isArray(v.groups) ? (v.groups as unknown[]) : [];
  const roles = Array.isArray(v.roles) ? (v.roles as unknown[]) : [];

  if (calculations.length === 0 || properties.length === 0 || regulators.length === 0 || groups.length === 0 || roles.length === 0) {
    gaps.push({ property: 'vocabulary', reason: FAMILY_REASONS.VOCABULARY_INVALID });
  }
  const calculationIds = calculations.map((c) => String(c.id));
  const propertyIds = properties.map((p) => String(p.id));
  for (const id of [...duplicates(calculationIds), ...duplicates(propertyIds), ...duplicates(roles.map(String))]) {
    gaps.push({ property: `vocabulary:${id}`, reason: FAMILY_REASONS.LIST_DUPLICATE });
  }
  const sources = input.sources as Array<Record<string, unknown>>;
  const sourceIds = sources.map((s) => String(s.id));

  if (!Array.isArray(input.families) || input.families.length === 0) {
    return missing([...gaps, { property: 'families', reason: FAMILY_REASONS.NO_FAMILIES }]);
  }
  const families = input.families as Array<Record<string, unknown>>;
  const codes = families.map((f) => String(f.code));

  families.forEach((f, index) => {
    const at = (field: string): string => `families[${index}].${field}`;
    if (typeof f.code !== 'string' || !CODE.test(f.code)) {
      gaps.push({ property: at('code'), reason: FAMILY_REASONS.CODE_INVALID });
    } else if (codes.indexOf(f.code) !== index) {
      gaps.push({ property: at('code'), reason: FAMILY_REASONS.CODE_DUPLICATE });
    }
    if (localizedNameIssue(f.name)) {
      gaps.push({ property: at('name'), reason: FAMILY_REASONS.NAME_MISSING });
    }
    if (!groups.includes(f.group)) {
      gaps.push({ property: at('group'), reason: FAMILY_REASONS.GROUP_UNKNOWN });
    }
    if (f.regulator !== null && !regulators.includes(f.regulator)) {
      gaps.push({ property: at('regulator'), reason: FAMILY_REASONS.REGULATOR_UNKNOWN });
    }
    if (f.ifraCategory !== null && !(typeof f.ifraCategory === 'string' && IFRA.test(f.ifraCategory))) {
      gaps.push({ property: at('ifraCategory'), reason: FAMILY_REASONS.IFRA_INVALID });
    }
    for (const [field, list, vocab, reason] of [
      ['requiredCalculations', f.requiredCalculations, calculationIds, FAMILY_REASONS.CALCULATION_UNKNOWN],
      ['measuredProperties', f.measuredProperties, propertyIds, FAMILY_REASONS.PROPERTY_UNKNOWN],
    ] as const) {
      const items = Array.isArray(list) ? (list as unknown[]).map(String) : null;
      if (items === null) {
        gaps.push({ property: at(field), reason });
        continue;
      }
      items.filter((id) => !vocab.includes(id)).forEach((id) => gaps.push({ property: at(`${field}:${id}`), reason }));
      duplicates(items).forEach((id) => gaps.push({ property: at(`${field}:${id}`), reason: FAMILY_REASONS.LIST_DUPLICATE }));
    }
    if (f.taxRegime !== null && !isText(f.taxRegime)) {
      gaps.push({ property: at('taxRegime'), reason: FAMILY_REASONS.NOT_OBJECT });
    }
    if (typeof f.sourceId !== 'string' || !sourceIds.includes(f.sourceId)) {
      gaps.push({ property: at('sourceId'), reason: FAMILY_REASONS.SOURCE_UNKNOWN });
    }
    if (!(CONFIDENCE_LEVELS as readonly unknown[]).includes(f.confidence)) {
      gaps.push({ property: at('confidence'), reason: FAMILY_REASONS.CONFIDENCE_INVALID });
    }
    if (f.verification !== 'pending' && f.verification !== 'verified') {
      gaps.push({ property: at('verification'), reason: FAMILY_REASONS.VERIFICATION_INVALID });
    } else if (f.verification === 'verified' && f.confidence === 'L7_estimate') {
      gaps.push({ property: at('verification'), reason: FAMILY_REASONS.VERIFIED_NEEDS_SOURCE });
    }
  });

  return gaps.length > 0 ? missing(gaps) : ok(input as unknown as ProductFamilyRegistry);
}

export function familyByCode(registry: ProductFamilyRegistry, code: string): ProductFamily | undefined {
  return registry.families.find((f) => f.code === code);
}

/**
 * The lab properties a family needs measured, by the lab loop's own names. Properties the lab cannot record yet
 * (`labProperty` null) are returned separately so the gap stays visible instead of being dropped.
 */
export function familyLabRequirements(registry: ProductFamilyRegistry, code: string): { lab: string[]; notYetRecordable: string[] } | undefined {
  const family = familyByCode(registry, code);
  if (family === undefined) {
    return undefined;
  }
  const lab: string[] = [];
  const notYetRecordable: string[] = [];
  for (const id of family.measuredProperties) {
    const entry = registry.vocabulary.measuredProperties.find((p) => p.id === id);
    if (entry?.labProperty) {
      lab.push(entry.labProperty);
    } else {
      notYetRecordable.push(id);
    }
  }
  return { lab, notYetRecordable };
}
