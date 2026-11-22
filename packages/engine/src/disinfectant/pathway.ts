/**
 * The disinfectant EDA pathway notice (N-12). A product that makes a disinfection claim, or belongs to a disinfectant family, is
 * licensed on a separate EDA path (the guide for licensing disinfectant preparation factories/lines, EDREX:GL.CALI.009, 2026).
 * The program only alerts: it never approves, and it states no requirement of the guide. Everything it says comes from
 * data/disinfectant-flag/disinfectant-flag.json (a claim that triggers the path, a claim whose effect is not yet decided, the families that imply it);
 * nothing regulatory is written in this code. A claim the data has not decided (`triggersPathway: null`) is "review", never "none".
 */
import type { MissingData } from '../contract';
import { missing, ok, type Result } from '../core/result';
import { CONFIDENCE_LEVELS } from '../families/registry';
import type { ProductFamilyRegistry, RegistrySource } from '../families/types';
import flagJson from '../../data/disinfectant-flag/disinfectant-flag.json';

export const DISINFECTANT_FLAG_REASONS = {
  NOT_OBJECT: 'disinfectant.flag_not_object',
  GROUP_UNKNOWN: 'disinfectant.flag_group_unknown',
  REGULATOR_UNKNOWN: 'disinfectant.flag_regulator_unknown',
  GUIDE_MISSING: 'disinfectant.flag_guide_missing',
  CLAIM_INVALID: 'disinfectant.flag_claim_invalid',
  CLAIM_DUPLICATE: 'disinfectant.flag_claim_duplicate',
  SOURCE_UNKNOWN: 'disinfectant.flag_source_unknown',
  CONFIDENCE_INVALID: 'disinfectant.flag_confidence_invalid',
  CLAIM_UNKNOWN: 'disinfectant.claim_unknown',
} as const;

export interface DisinfectantClaim {
  code: string;
  /** true = the claim puts the product on the EDA disinfectant path; null = not decided by the sources yet (HUMAN-TODO). */
  triggersPathway: boolean | null;
  sourceId: string;
}

export interface DisinfectantFlagData {
  id: string;
  version: string;
  status: string;
  sources: RegistrySource[];
  pathway: { regulator: string; guideCode: string; guideEdition: string; sourceId: string };
  /** Registry groups whose every family is on the path whatever the claims. */
  impliedByGroups: string[];
  claims: DisinfectantClaim[];
}

const CODE = /^[a-z][a-z0-9_]*$/;
const isRecord = (x: unknown): x is Record<string, unknown> => typeof x === 'object' && x !== null && !Array.isArray(x);
const isText = (x: unknown): x is string => typeof x === 'string' && x.trim() !== '';

/** The shipped data file, as the browser and the scripts read it (validated by validateDisinfectantFlag in the tests and at seeding). */
export const DISINFECTANT_FLAG = flagJson as unknown as DisinfectantFlagData;

/** Checks the data file against the registry (groups, regulators) and refuses a claim or source it cannot resolve. */
export function validateDisinfectantFlag(input: unknown, registry: ProductFamilyRegistry): Result<DisinfectantFlagData> {
  if (!isRecord(input) || !isRecord(input.pathway) || !Array.isArray(input.sources) || !Array.isArray(input.claims) || !Array.isArray(input.impliedByGroups)) {
    return missing([{ property: '$', reason: DISINFECTANT_FLAG_REASONS.NOT_OBJECT }]);
  }
  const gaps: MissingData[] = [];
  const sources = input.sources as Array<Record<string, unknown>>;
  const sourceIds = sources.map((s) => String(s.id));
  sources.forEach((s, i) => {
    if (!(CONFIDENCE_LEVELS as readonly unknown[]).includes(s.confidence)) {
      gaps.push({ property: `sources[${i}].confidence`, reason: DISINFECTANT_FLAG_REASONS.CONFIDENCE_INVALID });
    }
  });
  const pathway = input.pathway;
  if (typeof pathway.regulator !== 'string' || !registry.vocabulary.regulators.includes(pathway.regulator as never)) {
    gaps.push({ property: 'pathway.regulator', reason: DISINFECTANT_FLAG_REASONS.REGULATOR_UNKNOWN });
  }
  if (!isText(pathway.guideCode) || !isText(pathway.guideEdition)) {
    gaps.push({ property: 'pathway.guideCode', reason: DISINFECTANT_FLAG_REASONS.GUIDE_MISSING });
  }
  if (typeof pathway.sourceId !== 'string' || !sourceIds.includes(pathway.sourceId)) {
    gaps.push({ property: 'pathway.sourceId', reason: DISINFECTANT_FLAG_REASONS.SOURCE_UNKNOWN });
  }
  (input.impliedByGroups as unknown[]).forEach((g, i) => {
    if (typeof g !== 'string' || !registry.vocabulary.groups.includes(g)) {
      gaps.push({ property: `impliedByGroups[${i}]`, reason: DISINFECTANT_FLAG_REASONS.GROUP_UNKNOWN });
    }
  });
  const claims = input.claims as Array<Record<string, unknown>>;
  const codes = claims.map((c) => String(c.code));
  claims.forEach((c, i) => {
    const at = (field: string): string => `claims[${i}].${field}`;
    if (typeof c.code !== 'string' || !CODE.test(c.code) || (c.triggersPathway !== null && typeof c.triggersPathway !== 'boolean')) {
      gaps.push({ property: at('code'), reason: DISINFECTANT_FLAG_REASONS.CLAIM_INVALID });
    } else if (codes.indexOf(c.code) !== i) {
      gaps.push({ property: at('code'), reason: DISINFECTANT_FLAG_REASONS.CLAIM_DUPLICATE });
    }
    if (typeof c.sourceId !== 'string' || !sourceIds.includes(c.sourceId)) {
      gaps.push({ property: at('sourceId'), reason: DISINFECTANT_FLAG_REASONS.SOURCE_UNKNOWN });
    }
  });
  return gaps.length > 0 ? missing(gaps) : ok(input as unknown as DisinfectantFlagData);
}

/** `applies`: show the separate EDA path notice. `review`: a claim the sources have not decided is made; a person must. `none`: nothing triggers it. */
export type PathwayStatus = 'applies' | 'review' | 'none';

export interface PathwayTrigger {
  kind: 'family_group' | 'claim';
  code: string;
}

export interface DisinfectionNotice {
  status: PathwayStatus;
  /** What puts the product on the path (family group and/or claims). */
  triggers: PathwayTrigger[];
  /** Claims made whose effect the sources do not decide. */
  reviewClaims: string[];
  /** The pathway to name in the notice; null when status is `none`. */
  pathway: DisinfectantFlagData['pathway'] | null;
  sourceId: string | null;
}

export interface DisinfectionNoticeInput {
  /** The registry group of the product's family, when it has one. */
  familyGroup?: string | null;
  /** The claim codes the product makes (data.claims). */
  claims?: readonly string[];
}

/** Whether a product is on the separate EDA disinfectant path. An unknown claim code is reported, never ignored. */
export function disinfectionPathwayNotice(data: DisinfectantFlagData, input: DisinfectionNoticeInput): Result<DisinfectionNotice> {
  const claims = [...new Set(input.claims ?? [])];
  const unknown = claims.filter((code) => !data.claims.some((c) => c.code === code));
  if (unknown.length > 0) {
    return missing(unknown.map((code) => ({ property: `claims:${code}`, reason: DISINFECTANT_FLAG_REASONS.CLAIM_UNKNOWN })));
  }
  const triggers: PathwayTrigger[] = [];
  if (input.familyGroup != null && data.impliedByGroups.includes(input.familyGroup)) {
    triggers.push({ kind: 'family_group', code: input.familyGroup });
  }
  const reviewClaims: string[] = [];
  for (const code of claims) {
    const claim = data.claims.find((c) => c.code === code) as DisinfectantClaim;
    if (claim.triggersPathway === true) {
      triggers.push({ kind: 'claim', code });
    } else if (claim.triggersPathway === null) {
      reviewClaims.push(code);
    }
  }
  const status: PathwayStatus = triggers.length > 0 ? 'applies' : reviewClaims.length > 0 ? 'review' : 'none';
  return ok({ status, triggers, reviewClaims, pathway: status === 'none' ? null : data.pathway, sourceId: status === 'none' ? null : data.pathway.sourceId });
}
