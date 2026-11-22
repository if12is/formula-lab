/* eslint-disable @typescript-eslint/no-explicit-any */
import { describe, expect, it } from 'vitest';
import registryJson from '../../data/product-families.json';
import { validateProductFamilyRegistry } from '../families/registry';
import type { ProductFamilyRegistry } from '../families/types';
import { DISINFECTANT_FLAG, DISINFECTANT_FLAG_REASONS, disinfectionPathwayNotice, validateDisinfectantFlag } from './pathway';

const registryResult = validateProductFamilyRegistry(registryJson);
const registry = (registryResult.ok ? registryResult.value : (undefined as never)) as ProductFamilyRegistry;
const clone = <T>(x: T): T => JSON.parse(JSON.stringify(x)) as T;
const notice = (input: Parameters<typeof disinfectionPathwayNotice>[1]) => {
  const r = disinfectionPathwayNotice(DISINFECTANT_FLAG, input);
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r.value;
};

describe('the shipped disinfectant flag data (N-12)', () => {
  it('validates against the registry and names only what the research states: the EDA guide EDREX:GL.CALI.009 (2026), no requirement of it', () => {
    expect(validateDisinfectantFlag(DISINFECTANT_FLAG, registry).ok).toBe(true);
    expect(DISINFECTANT_FLAG.pathway).toEqual({ regulator: 'EDA', guideCode: 'EDREX:GL.CALI.009', guideEdition: '2026', sourceId: 'plan-00-research-03' });
    expect(DISINFECTANT_FLAG.impliedByGroups).toEqual(['disinfectants']);
  });

  it('a disinfection claim triggers the path; an antibacterial claim is left undecided (null), not guessed', () => {
    expect(DISINFECTANT_FLAG.claims.map((c) => [c.code, c.triggersPathway])).toEqual([['disinfection', true], ['antibacterial', null]]);
  });
});

describe('the notice (hand cases)', () => {
  it('a shampoo (personal care, EDA) that claims disinfection: the separate EDA path applies, triggered by the claim', () => {
    const n = notice({ familyGroup: 'personal_care', claims: ['disinfection'] });
    expect(n.status).toBe('applies');
    expect(n.triggers).toEqual([{ kind: 'claim', code: 'disinfection' }]);
    expect(n.pathway?.guideCode).toBe('EDREX:GL.CALI.009');
    expect(n.sourceId).toBe('plan-00-research-03');
  });

  it('a disinfectant family with no claim typed: applies, triggered by the family group alone', () => {
    const n = notice({ familyGroup: 'disinfectants', claims: [] });
    expect(n.status).toBe('applies');
    expect(n.triggers).toEqual([{ kind: 'family_group', code: 'disinfectants' }]);
  });

  it('both the family and the claim are listed, and a repeated claim counts once', () => {
    const n = notice({ familyGroup: 'disinfectants', claims: ['disinfection', 'disinfection'] });
    expect(n.triggers).toEqual([{ kind: 'family_group', code: 'disinfectants' }, { kind: 'claim', code: 'disinfection' }]);
  });

  it('a dish liquid with no claim: nothing triggers, no pathway named', () => {
    const n = notice({ familyGroup: 'detergents', claims: [] });
    expect(n).toEqual({ status: 'none', triggers: [], reviewClaims: [], pathway: null, sourceId: null });
  });

  it('only an antibacterial claim (undecided): review, never none and never applies', () => {
    const n = notice({ familyGroup: 'detergents', claims: ['antibacterial'] });
    expect(n.status).toBe('review');
    expect(n.reviewClaims).toEqual(['antibacterial']);
    expect(n.pathway?.regulator).toBe('EDA');
  });

  it('a decided trigger wins over an undecided claim', () => {
    expect(notice({ claims: ['antibacterial', 'disinfection'] }).status).toBe('applies');
  });

  it('a formula with no family and no claims: none', () => {
    expect(notice({}).status).toBe('none');
    expect(notice({ familyGroup: null }).status).toBe('none');
  });

  it('an unknown claim code is reported, never ignored', () => {
    const r = disinfectionPathwayNotice(DISINFECTANT_FLAG, { claims: ['germ_free'] });
    expect(r.ok).toBe(false);
    expect(r.ok ? [] : r.missing).toEqual([{ property: 'claims:germ_free', reason: DISINFECTANT_FLAG_REASONS.CLAIM_UNKNOWN }]);
  });
});

describe('validation of the data file reports what it refuses', () => {
  const reasons = (doc: unknown): string[] => {
    const r = validateDisinfectantFlag(doc, registry);
    return r.ok ? [] : r.missing.map((m) => m.reason);
  };
  it.each([
    ['unknown group', (d: any) => { d.impliedByGroups.push('perfumes'); }, DISINFECTANT_FLAG_REASONS.GROUP_UNKNOWN],
    ['unknown regulator', (d: any) => { d.pathway.regulator = 'FDA'; }, DISINFECTANT_FLAG_REASONS.REGULATOR_UNKNOWN],
    ['no guide code', (d: any) => { d.pathway.guideCode = ''; }, DISINFECTANT_FLAG_REASONS.GUIDE_MISSING],
    ['duplicate claim', (d: any) => { d.claims[1].code = 'disinfection'; }, DISINFECTANT_FLAG_REASONS.CLAIM_DUPLICATE],
    ['trigger that is neither true, false nor null', (d: any) => { d.claims[0].triggersPathway = 'yes'; }, DISINFECTANT_FLAG_REASONS.CLAIM_INVALID],
    ['unknown source', (d: any) => { d.claims[0].sourceId = 'x'; }, DISINFECTANT_FLAG_REASONS.SOURCE_UNKNOWN],
    ['bad confidence', (d: any) => { d.sources[0].confidence = 'L9'; }, DISINFECTANT_FLAG_REASONS.CONFIDENCE_INVALID],
  ])('refuses %s', (_label, change, reason) => {
    const doc = clone(DISINFECTANT_FLAG) as any;
    change(doc);
    expect(reasons(doc)).toContain(reason);
  });

  it('not an object => one reason, not a throw', () => {
    expect(reasons(null)).toEqual([DISINFECTANT_FLAG_REASONS.NOT_OBJECT]);
  });
});
