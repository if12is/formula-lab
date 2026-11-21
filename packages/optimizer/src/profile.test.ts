import { describe, expect, it } from 'vitest';
import { optimize } from './optimize';
import { requiredFieldsMissing, TIER_PROFILE_JSON_SCHEMA, validateTierProfile } from './profile';
import { CASE1_MATERIALS, profile } from './test-fixtures';

const valid = (): Record<string, unknown> => JSON.parse(JSON.stringify(profile({ totalActiveMinPct: '10', functionBounds: [{ function: 'f', min: '1', max: '5' }] }))) as Record<string, unknown>;
const errorsOf = (raw: unknown): string[] => {
  const r = validateTierProfile(raw);
  return r.ok ? [] : r.errors.map((e) => `${e.path}|${e.key}`);
};

describe('J-01 profile validator', () => {
  it('accepts a complete profile, nulls included, and ships no filled profile', () => {
    expect(validateTierProfile(valid()).ok).toBe(true);
    expect(validateTierProfile(profile()).ok).toBe(true);
  });

  it('rejects JS numbers, unknown keys, bad tiers and out-of-range values', () => {
    const p = valid() as { tier: string; extra?: number; constraints: Record<string, unknown> };
    p.tier = 'gold';
    p.extra = 1;
    p.constraints.totalActiveMinPct = 12;
    p.constraints.maxCostEGPperKg = '-1';
    expect(errorsOf(p)).toEqual(expect.arrayContaining([
      'tier|optimizer.profile.invalid_tier', 'extra|optimizer.profile.unknown_field',
      'constraints.totalActiveMinPct|optimizer.profile.not_decimal_string', 'constraints.maxCostEGPperKg|optimizer.profile.out_of_range',
    ]));
  });

  it('requires every constraint key to be present (null is explicit, absence is an error)', () => {
    const p = valid() as { constraints: Record<string, unknown> };
    delete p.constraints.totalActiveMaxPct;
    expect(errorsOf(p)).toContain('constraints.totalActiveMaxPct|optimizer.profile.field_required');
  });

  it('requires a source for every non-null value (rule 2)', () => {
    const p = valid() as { sources: Record<string, string> };
    p.sources = {};
    expect(errorsOf(p)).toEqual(expect.arrayContaining(['sources.totalActiveMinPct|optimizer.profile.source_required', 'sources.functionBounds.f|optimizer.profile.source_required']));
  });

  it('checks min <= max, duplicates, and required/forbidden overlap', () => {
    const p = valid() as { constraints: { functionBounds: { min: string; max: string }[]; requiredMaterials: string[]; forbiddenMaterials: string[] } };
    p.constraints.functionBounds[0] = { function: 'f', min: '9', max: '5' } as never;
    p.constraints.requiredMaterials = ['X'];
    p.constraints.forbiddenMaterials = ['X'];
    expect(errorsOf(p)).toEqual(expect.arrayContaining(['constraints.functionBounds[0]|optimizer.profile.min_exceeds_max', 'constraints.requiredMaterials|optimizer.profile.required_and_forbidden']));
  });

  it('the JSON schema lists the same required keys as the validator enforces', () => {
    expect(TIER_PROFILE_JSON_SCHEMA.properties.constraints.required).toEqual(expect.arrayContaining(Object.keys(profile().constraints)));
    expect([...TIER_PROFILE_JSON_SCHEMA.required].sort()).toEqual(Object.keys(profile()).sort());
  });
});

describe('required fields (configurable)', () => {
  it('lists each null the product needs as profile.<field>', () => {
    const p = profile({ functionBounds: [{ function: 'f', min: null, max: '3' }] });
    expect(requiredFieldsMissing(p, ['totalActiveMinPct', 'maxCostEGPperKg', 'functionBounds.f.min', 'functionBounds.f.max', 'functionBounds.g.min']).map((m) => m.property)).toEqual([
      'profile.totalActiveMinPct', 'profile.maxCostEGPperKg', 'profile.functionBounds.f.min', 'profile.functionBounds.g.min',
    ]);
  });

  it('a null totalActiveMinPct stops the optimizer with missing profile.totalActiveMinPct (no solve)', async () => {
    const out = await optimize({ profile: profile(), materials: CASE1_MATERIALS, qsMaterialId: 'WATER' });
    expect(out.ok).toBe(false);
    expect(out.status).toBe('missing');
    expect(out.missing).toEqual([{ property: 'profile.totalActiveMinPct', reason: 'optimizer.profile.field_null_required' }]);
    expect(out.formula).toEqual([]);
    expect(out.solver.lpSolves).toBe('0');
  });

  it('requiredFields is configurable: empty list solves a profile with all nulls', async () => {
    const out = await optimize({ profile: profile(), materials: [CASE1_MATERIALS[0]!], qsMaterialId: 'WATER', policy: { requiredFields: [] } });
    expect(out.status).toBe('optimal');
    expect(out.formula.map((l) => [l.materialId, l.percentWW])).toEqual([['WATER', '100.000']]);
  });

  it('a stricter product can require more (maxCostEGPperKg)', async () => {
    const out = await optimize({ profile: profile({ totalActiveMinPct: '10' }), materials: CASE1_MATERIALS, policy: { requiredFields: ['totalActiveMinPct', 'maxCostEGPperKg'] } });
    expect(out.missing.map((m) => m.property)).toEqual(['profile.maxCostEGPperKg']);
  });

  it('an invalid profile is reported as invalid with paths, not solved', async () => {
    const out = await optimize({ profile: { nope: true }, materials: CASE1_MATERIALS });
    expect(out.status).toBe('invalid');
    expect(out.errors.length).toBeGreaterThan(0);
  });
});
