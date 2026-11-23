/**
 * The EDA notification file assistant (N-08): which documents a product's file needs, and how ready the file is.
 * What the file must contain is data (data/eda-checklist/eda-checklist.json): nothing regulatory is written in this code. The research the
 * project holds names the EDA decision and guides but never lists their required documents, so every list there is `items: null` (HUMAN-TODO)
 * and the assistant says "the list is not sourced" instead of inventing one or calling the file ready. When items are added to the data,
 * each one needs a name and a source, and the per-item status (missing, attached, not applicable) and the overall readiness work on them.
 */
import type { MissingData } from '../contract';
import { missing, ok, type Result } from '../core/result';
import { CONFIDENCE_LEVELS } from '../families/registry';
import type { ProductFamilyRegistry, RegistrySource } from '../families/types';
import checklistJson from '../../data/eda-checklist/eda-checklist.json';

export const EDA_CHECKLIST_REASONS = {
  NOT_OBJECT: 'eda_checklist.not_object',
  GROUP_UNKNOWN: 'eda_checklist.group_unknown',
  GROUP_DUPLICATE: 'eda_checklist.group_duplicate',
  REGULATOR_UNKNOWN: 'eda_checklist.regulator_unknown',
  REFERENCE_INVALID: 'eda_checklist.reference_invalid',
  SOURCE_UNKNOWN: 'eda_checklist.source_unknown',
  CONFIDENCE_INVALID: 'eda_checklist.confidence_invalid',
  ITEMS_INVALID: 'eda_checklist.items_invalid',
  ITEM_INVALID: 'eda_checklist.item_invalid',
  ITEM_DUPLICATE: 'eda_checklist.item_duplicate',
  ENTRY_UNKNOWN_ITEM: 'eda_checklist.entry_unknown_item',
  ENTRY_STATUS_INVALID: 'eda_checklist.entry_status_invalid',
} as const;

export const EDA_ITEM_STATUSES = ['missing', 'attached', 'not_applicable'] as const;
export type EdaItemStatus = (typeof EDA_ITEM_STATUSES)[number];

export interface EdaChecklistItem {
  code: string;
  name: { ar: string; en: string };
  sourceId: string;
}

export interface EdaChecklistDefinition {
  id: string;
  regulator: string;
  appliesToGroups: string[];
  reference: { title: { ar: string; en: string }; sourceId: string };
  /** The documents the file needs; null until a source states them (HUMAN-TODO). */
  items: EdaChecklistItem[] | null;
}

export interface EdaChecklistData {
  id: string;
  version: string;
  status: string;
  sources: RegistrySource[];
  checklists: EdaChecklistDefinition[];
}

const CODE = /^[a-z][a-z0-9_]*$/;
const isRecord = (x: unknown): x is Record<string, unknown> => typeof x === 'object' && x !== null && !Array.isArray(x);
const isText = (x: unknown): x is string => typeof x === 'string' && x.trim() !== '';
const isName = (x: unknown): boolean => isRecord(x) && isText(x.ar) && isText(x.en);

/** The shipped data file (validated by validateEdaChecklist in the tests and at the server's first read). */
export const EDA_CHECKLIST = checklistJson as unknown as EdaChecklistData;

/** Checks the data file against the family registry (groups, regulators) and refuses an item without a name or a source. */
export function validateEdaChecklist(input: unknown, registry: ProductFamilyRegistry): Result<EdaChecklistData> {
  if (!isRecord(input) || !Array.isArray(input.sources) || !Array.isArray(input.checklists)) {
    return missing([{ property: '$', reason: EDA_CHECKLIST_REASONS.NOT_OBJECT }]);
  }
  const gaps: MissingData[] = [];
  const sources = input.sources as Array<Record<string, unknown>>;
  const sourceIds = sources.map((s) => String(s.id));
  sources.forEach((s, i) => {
    if (!(CONFIDENCE_LEVELS as readonly unknown[]).includes(s.confidence)) {
      gaps.push({ property: `sources[${i}].confidence`, reason: EDA_CHECKLIST_REASONS.CONFIDENCE_INVALID });
    }
  });
  const seenGroups: string[] = [];
  (input.checklists as Array<Record<string, unknown>>).forEach((list, i) => {
    const at = (field: string): string => `checklists[${i}].${field}`;
    if (!isRecord(list) || !isText(list.id) || !CODE.test(list.id)) {
      gaps.push({ property: at('id'), reason: EDA_CHECKLIST_REASONS.NOT_OBJECT });
      return;
    }
    if (typeof list.regulator !== 'string' || !registry.vocabulary.regulators.includes(list.regulator as never)) {
      gaps.push({ property: at('regulator'), reason: EDA_CHECKLIST_REASONS.REGULATOR_UNKNOWN });
    }
    const groups = Array.isArray(list.appliesToGroups) ? (list.appliesToGroups as unknown[]) : [];
    if (groups.length === 0) {
      gaps.push({ property: at('appliesToGroups'), reason: EDA_CHECKLIST_REASONS.GROUP_UNKNOWN });
    }
    groups.forEach((g, j) => {
      if (typeof g !== 'string' || !registry.vocabulary.groups.includes(g)) {
        gaps.push({ property: `${at('appliesToGroups')}[${j}]`, reason: EDA_CHECKLIST_REASONS.GROUP_UNKNOWN });
      } else if (seenGroups.includes(g)) {
        gaps.push({ property: `${at('appliesToGroups')}[${j}]`, reason: EDA_CHECKLIST_REASONS.GROUP_DUPLICATE });
      } else {
        seenGroups.push(g);
      }
    });
    const reference = list.reference;
    if (!isRecord(reference) || !isName(reference.title)) {
      gaps.push({ property: at('reference'), reason: EDA_CHECKLIST_REASONS.REFERENCE_INVALID });
    } else if (typeof reference.sourceId !== 'string' || !sourceIds.includes(reference.sourceId)) {
      gaps.push({ property: at('reference.sourceId'), reason: EDA_CHECKLIST_REASONS.SOURCE_UNKNOWN });
    }
    if (list.items === null) {
      return;
    }
    if (!Array.isArray(list.items) || list.items.length === 0) {
      gaps.push({ property: at('items'), reason: EDA_CHECKLIST_REASONS.ITEMS_INVALID });
      return;
    }
    const codes = (list.items as Array<Record<string, unknown>>).map((item) => String(item?.code));
    (list.items as Array<Record<string, unknown>>).forEach((item, k) => {
      const itemAt = (field: string): string => `${at('items')}[${k}].${field}`;
      if (!isRecord(item) || typeof item.code !== 'string' || !CODE.test(item.code) || !isName(item.name)) {
        gaps.push({ property: itemAt('code'), reason: EDA_CHECKLIST_REASONS.ITEM_INVALID });
      } else if (codes.indexOf(item.code) !== k) {
        gaps.push({ property: itemAt('code'), reason: EDA_CHECKLIST_REASONS.ITEM_DUPLICATE });
      }
      if (!isRecord(item) || typeof item.sourceId !== 'string' || !sourceIds.includes(item.sourceId)) {
        gaps.push({ property: itemAt('sourceId'), reason: EDA_CHECKLIST_REASONS.SOURCE_UNKNOWN });
      }
    });
  });
  return gaps.length > 0 ? missing(gaps) : ok(input as unknown as EdaChecklistData);
}

/**
 * `out_of_scope`: the product's family group has no EDA checklist (an EOS product). `items_not_sourced`: the checklist exists but no source lists its
 * documents yet, so the readiness cannot be judged. `incomplete`: some document is still missing. `ready`: every item is attached or marked not applicable.
 */
export type EdaReadinessState = 'out_of_scope' | 'items_not_sourced' | 'incomplete' | 'ready';

export interface EdaItemView extends EdaChecklistItem {
  status: EdaItemStatus;
}

export interface EdaReadiness {
  state: EdaReadinessState;
  checklistId: string | null;
  regulator: string | null;
  reference: EdaChecklistDefinition['reference'] | null;
  items: EdaItemView[];
  /** null while the items are not sourced: a count of nothing is not zero. */
  counts: { total: number; attached: number; missing: number; notApplicable: number } | null;
}

export interface EdaReadinessInput {
  /** The registry group of the product's family. */
  familyGroup: string | null;
  /** What was recorded per item code; an item with no entry is `missing`. */
  statuses?: Readonly<Record<string, string>>;
}

/** The checklist of a product and how complete its file is. An entry for an item the checklist does not have, or a status that is not one of the three, is reported. */
export function edaReadiness(data: EdaChecklistData, input: EdaReadinessInput): Result<EdaReadiness> {
  const list = input.familyGroup === null ? undefined : data.checklists.find((candidate) => candidate.appliesToGroups.includes(input.familyGroup as string));
  if (list === undefined) {
    return ok({ state: 'out_of_scope', checklistId: null, regulator: null, reference: null, items: [], counts: null });
  }
  const base = { checklistId: list.id, regulator: list.regulator, reference: list.reference };
  const statuses = input.statuses ?? {};
  if (list.items === null) {
    return ok({ state: 'items_not_sourced', ...base, items: [], counts: null }, ['eda_checklist.items_not_sourced']);
  }
  const gaps: MissingData[] = [];
  for (const [code, status] of Object.entries(statuses)) {
    if (!(list.items as EdaChecklistItem[]).some((item) => item.code === code)) {
      gaps.push({ property: `entries:${code}`, reason: EDA_CHECKLIST_REASONS.ENTRY_UNKNOWN_ITEM });
    } else if (!(EDA_ITEM_STATUSES as readonly string[]).includes(status)) {
      gaps.push({ property: `entries:${code}`, reason: EDA_CHECKLIST_REASONS.ENTRY_STATUS_INVALID });
    }
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const items: EdaItemView[] = list.items.map((item) => ({ ...item, status: (statuses[item.code] as EdaItemStatus | undefined) ?? 'missing' }));
  const count = (status: EdaItemStatus): number => items.filter((item) => item.status === status).length;
  const counts = { total: items.length, attached: count('attached'), missing: count('missing'), notApplicable: count('not_applicable') };
  return ok({ state: counts.missing === 0 ? 'ready' : 'incomplete', ...base, items, counts });
}
