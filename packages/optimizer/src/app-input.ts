/**
 * S-05: what the tiers screen hands the optimizer. The app sends each material's price QUOTES (money per kg, any currency) and
 * the dated exchange rates; this module picks the quote with the engine's own `resolveValue` and converts it to EGP with the
 * engine's `sourcedEgp`, exactly as the cost report does, so a price here is the price the cost report would use.
 *
 * A material that has no usable price or no active-matter value is NOT offered to the LP and is listed in `excluded` with the
 * engine's reason: the answer then says which materials it was computed without, instead of stopping on the first gap or
 * treating the gap as zero. A material the profile requires or bounds is still reported by the optimizer itself as missing.
 */
import { resolveValue, sourcedEgp, type Candidate, type FxRate } from '@fl/engine';
import type { OptimizerMaterial, SourcedTags } from './optimize';
import { sourcedFieldsOf, type TierProfile } from './profile';

export interface MaterialQuotes {
  materialId: string;
  /** Price quotes, money per kg, in the currency they were quoted in. */
  quotes: Candidate[];
  /** Active-matter values on file, unit '%w/w' (the engine picks one, as for a price). None = not recorded: the material is excluded, never read as 0. */
  activeMatter?: Candidate[];
  functions?: SourcedTags;
}

export interface ExcludedMaterial {
  materialId: string;
  /** Message key of the engine's reason (e.g. `cost.fx_rate_required`, `no_candidates`, `input.missing`). */
  reason: string;
  /** Which input the reason is about: `price` or `activeMatterPct`. */
  property: 'price' | 'activeMatterPct';
}

export interface OfferedMaterials {
  materials: OptimizerMaterial[];
  excluded: ExcludedMaterial[];
}

export function offeredMaterials(items: readonly MaterialQuotes[], fxRates: readonly FxRate[]): OfferedMaterials {
  const materials: OptimizerMaterial[] = [];
  const excluded: ExcludedMaterial[] = [];
  for (const item of [...items].sort((a, b) => (a.materialId < b.materialId ? -1 : a.materialId > b.materialId ? 1 : 0))) {
    const id = item.materialId;
    const resolved = resolveValue('price', item.quotes, {}, id);
    if (!resolved.ok || resolved.value.chosenBy !== 'rule') {
      excluded.push({ materialId: id, property: 'price', reason: resolved.ok ? 'optimizer.input.price_conflict' : (resolved.missing[0]?.reason ?? 'input.missing') });
      continue;
    }
    const chosen = resolved.value.chosen as Candidate;
    const egp = chosen.value === undefined ? undefined : sourcedEgp(id, 'price', `${id} price`, { value: chosen.value, unit: chosen.unit, sourceId: chosen.sourceId, confidence: chosen.confidence, validFrom: chosen.validFrom }, { policy: {}, fxRates });
    if (egp === undefined || !egp.ok) {
      excluded.push({ materialId: id, property: 'price', reason: egp === undefined ? 'input.missing' : (egp.missing[0]?.reason ?? 'input.missing') });
      continue;
    }
    const activeResolved = resolveValue('active_matter', item.activeMatter ?? [], {}, id);
    const activeChosen = activeResolved.ok && activeResolved.value.chosenBy === 'rule' ? (activeResolved.value.chosen as Candidate) : undefined;
    if (activeChosen === undefined || activeChosen.value === undefined) {
      excluded.push({ materialId: id, property: 'activeMatterPct', reason: activeResolved.ok ? 'input.missing' : (activeResolved.missing[0]?.reason ?? 'input.missing') });
      continue;
    }
    materials.push({
      materialId: id,
      price: { value: egp.value.value, unit: 'EGP', sourceId: chosen.sourceId, confidence: chosen.confidence, validFrom: chosen.validFrom },
      activeMatterPct: { value: activeChosen.value, unit: activeChosen.unit, sourceId: activeChosen.sourceId, confidence: activeChosen.confidence, validFrom: activeChosen.validFrom },
      ...(item.functions !== undefined && { functions: item.functions }),
    });
  }
  return { materials, excluded };
}

/**
 * The profile with `sources` filled: every field that holds a value cites `sourceId` (the one source record the person chose for the
 * profile). With no source the map stays empty, so the optimizer's own validator reports each written figure as lacking one.
 */
export function withProfileSource(profile: TierProfile, sourceId: string | null): TierProfile {
  return { ...profile, sources: sourceId === null || sourceId === '' ? {} : Object.fromEntries(sourcedFieldsOf(profile).map((field) => [field, sourceId])) };
}
