/**
 * TEST-FIXTURE data. Every number in this file is FICTIONAL and exists only to exercise the optimizer
 * (hand-checkable LPs). Nothing here is chemistry or regulatory data and nothing is shipped as a profile.
 */
import type { SourcedScalar } from '@fl/engine';
import type { OptimizerInput, OptimizerMaterial } from './optimize';
import { sourcedFieldsOf, type TierConstraints, type TierProfile } from './profile';

const TEST = { sourceId: 'TEST-FIXTURE', confidence: 'L2_supplier_tds', validFrom: '2026-01-01' } as const;

export const egp = (value: string): SourcedScalar => ({ value, unit: 'EGP', ...TEST });
export const activePct = (value: string): SourcedScalar => ({ value, unit: '%w/w', ...TEST });

export function mat(materialId: string, pricePerKg: string, active: string, ...functions: string[]): OptimizerMaterial {
  return { materialId, price: egp(pricePerKg), activeMatterPct: activePct(active), functions: { tags: functions, ...TEST } };
}

export interface ProfileSpec extends Partial<TierConstraints> {
  retailPrice?: string | null;
  per?: 'L' | 'kg' | 'pack';
}

/** A profile with every constraint null unless given; every given value gets source TEST-FIXTURE. */
export function profile(spec: ProfileSpec = {}): TierProfile {
  const { retailPrice = null, per = 'pack', ...c } = spec;
  const p: TierProfile = {
    productType: 'test_product',
    tier: 'economy',
    targetRetailPrice: { per, value: retailPrice },
    constraints: {
      totalActiveMinPct: null,
      totalActiveMaxPct: null,
      functionBounds: [],
      requiredMaterials: [],
      forbiddenMaterials: [],
      maxCostEGPperKg: null,
      materialBounds: [],
      measuredRequirements: [],
      ...c,
    },
    sources: {},
  };
  p.sources = Object.fromEntries(sourcedFieldsOf(p).map((f) => [f, 'TEST-FIXTURE']));
  return p;
}

/** Hand case 1: A (100 EGP/kg, 50 % active), water (1 EGP/kg, 0 %); active >= 10 -> A 20, water 80, cost 20.8. */
export const CASE1_MATERIALS = [mat('WATER', '1', '0'), mat('AAA', '100', '50', 'surfactant')];

/**
 * Hand case 2: S1 (80, 40 % active, anionic), S2 (150, 60 %, amphoteric), WATER (1, 0 %);
 * active >= 12 and amphoteric >= 5 -> S1 22.5, S2 5, water 72.5, cost 26.225 EGP/kg.
 */
export const CASE2_MATERIALS = [
  mat('S1', '80', '40', 'anionic'),
  mat('S2', '150', '60', 'amphoteric'),
  mat('WATER', '1', '0'),
];
export const CASE2_PROFILE = profile({
  totalActiveMinPct: '12',
  functionBounds: [{ function: 'amphoteric', min: '5', max: null }],
});

export function case2(extra: Partial<OptimizerInput> = {}): OptimizerInput {
  return { profile: CASE2_PROFILE, materials: CASE2_MATERIALS, qsMaterialId: 'WATER', ...extra };
}

/** Deterministic PRNG for shuffles (no Math.random anywhere). */
export function seededShuffle<T>(items: readonly T[], seed: number): T[] {
  let s = seed >>> 0;
  const next = (): number => {
    s = (s + 0x6d2b79f5) >>> 0;
    let t = s;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
  const out = [...items];
  for (let i = out.length - 1; i > 0; i -= 1) {
    const j = Math.floor(next() * (i + 1));
    [out[i], out[j]] = [out[j] as T, out[i] as T];
  }
  return out;
}
