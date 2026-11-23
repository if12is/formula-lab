import { describe, expect, it } from 'vitest';
import registryJson from '../../data/product-families.json';
import { validateProductFamilyRegistry } from '../families/registry';
import type { ProductFamilyRegistry } from '../families/types';
import { EDA_CHECKLIST, EDA_CHECKLIST_REASONS, edaReadiness, validateEdaChecklist, type EdaChecklistData, type EdaChecklistDefinition } from './checklist';

const registryResult = validateProductFamilyRegistry(registryJson);
const registry = (registryResult.ok ? registryResult.value : (undefined as never)) as ProductFamilyRegistry;
const list = (data: EdaChecklistData, index: number): EdaChecklistDefinition => data.checklists[index] as EdaChecklistDefinition;
const clone = <T>(x: T): T => JSON.parse(JSON.stringify(x)) as T;

/** TEST-FIXTURE items: invented names to exercise the statuses. They are not a regulatory list and ship nowhere. */
const FIXTURE: EdaChecklistData = (() => {
  const data = clone(EDA_CHECKLIST);
  list(data, 0).items = ['doc_a', 'doc_b', 'doc_c'].map((code) => ({ code, name: { ar: `مستند ${code}`, en: `Document ${code}` }, sourceId: 'plan-00-research-03' }));
  return data;
})();

const readiness = (data: EdaChecklistData, familyGroup: string | null, statuses: Record<string, string> = {}) => {
  const r = edaReadiness(data, { familyGroup, statuses });
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r;
};

describe('the shipped EDA checklist data (N-08)', () => {
  it('validates against the registry', () => {
    expect(validateEdaChecklist(EDA_CHECKLIST, registry).ok).toBe(true);
  });

  it('lists no document: the project research names the EDA decision and guides but not what a file must contain, so every list is null', () => {
    expect(EDA_CHECKLIST.checklists.map((list) => [list.id, list.items])).toEqual([['eda_cosmetics_notification', null], ['eda_disinfectant_pathway', null]]);
  });

  it('covers exactly the registry groups whose families are regulated by EDA', () => {
    const edaGroups = [...new Set(registry.families.filter((family) => family.regulator === 'EDA').map((family) => family.group))].sort();
    expect(EDA_CHECKLIST.checklists.flatMap((list) => list.appliesToGroups).sort()).toEqual(edaGroups);
  });
});

describe('validation', () => {
  it('refuses an unknown group, a repeated group, an item without a source, a duplicate item and an empty list', () => {
    const bad = clone(FIXTURE);
    list(bad, 0).appliesToGroups = ['nonsense', 'disinfectants'];
    list(bad, 0).items = [
      { code: 'x', name: { ar: 'س', en: 'x' }, sourceId: 'nowhere' },
      { code: 'x', name: { ar: 'س', en: 'x' }, sourceId: 'plan-00-research-03' },
    ];
    list(bad, 1).items = [];
    const result = validateEdaChecklist(bad, registry);
    expect(result.ok).toBe(false);
    const reasons = result.ok ? [] : result.missing.map((gap) => gap.reason);
    expect(reasons).toEqual(expect.arrayContaining([
      EDA_CHECKLIST_REASONS.GROUP_UNKNOWN,
      EDA_CHECKLIST_REASONS.GROUP_DUPLICATE,
      EDA_CHECKLIST_REASONS.SOURCE_UNKNOWN,
      EDA_CHECKLIST_REASONS.ITEM_DUPLICATE,
      EDA_CHECKLIST_REASONS.ITEMS_INVALID,
    ]));
  });

  it('accepts a list with sourced items', () => {
    expect(validateEdaChecklist(FIXTURE, registry).ok).toBe(true);
  });
});

describe('readiness (hand cases)', () => {
  it('with the shipped data a cosmetic product is "items not sourced": no count, no ready, and a warning', () => {
    const r = readiness(EDA_CHECKLIST, 'skincare');
    expect(r.value.state).toBe('items_not_sourced');
    expect(r.value.counts).toBeNull();
    expect(r.value.items).toEqual([]);
    expect(r.value.checklistId).toBe('eda_cosmetics_notification');
    expect(r.warnings).toEqual(['eda_checklist.items_not_sourced']);
  });

  it('a detergent (EOS) is out of scope', () => {
    expect(readiness(EDA_CHECKLIST, 'detergents').value).toMatchObject({ state: 'out_of_scope', checklistId: null, counts: null });
    expect(readiness(EDA_CHECKLIST, null).value.state).toBe('out_of_scope');
  });

  it('an item with no entry is missing; 3 items with one attached = incomplete, counts 3 / 1 / 2 / 0', () => {
    const r = readiness(FIXTURE, 'personal_care', { doc_a: 'attached' });
    expect(r.value.state).toBe('incomplete');
    expect(r.value.counts).toEqual({ total: 3, attached: 1, missing: 2, notApplicable: 0 });
    expect(r.value.items.map((item) => [item.code, item.status])).toEqual([['doc_a', 'attached'], ['doc_b', 'missing'], ['doc_c', 'missing']]);
  });

  it('ready only when no item is missing: attached or not applicable', () => {
    const r = readiness(FIXTURE, 'skincare', { doc_a: 'attached', doc_b: 'not_applicable', doc_c: 'attached' });
    expect(r.value.state).toBe('ready');
    expect(r.value.counts).toEqual({ total: 3, attached: 2, missing: 0, notApplicable: 1 });
  });

  it('an explicit "missing" entry counts as missing', () => {
    expect(readiness(FIXTURE, 'skincare', { doc_a: 'attached', doc_b: 'attached', doc_c: 'missing' }).value.state).toBe('incomplete');
  });

  it('the disinfectant checklist has its own (null) list and is not filled by the cosmetics fixture', () => {
    expect(readiness(FIXTURE, 'disinfectants', { doc_a: 'attached' }).value.state).toBe('items_not_sourced');
  });

  it('an entry for an item the list does not have, or a status that is not one of the three, is reported and nothing is judged', () => {
    const unknown = edaReadiness(FIXTURE, { familyGroup: 'skincare', statuses: { doc_z: 'attached' } });
    expect(unknown.ok).toBe(false);
    expect(unknown.ok ? [] : unknown.missing).toEqual([{ property: 'entries:doc_z', reason: EDA_CHECKLIST_REASONS.ENTRY_UNKNOWN_ITEM }]);
    const invalid = edaReadiness(FIXTURE, { familyGroup: 'skincare', statuses: { doc_a: 'done' } });
    expect(invalid.ok ? [] : invalid.missing).toEqual([{ property: 'entries:doc_a', reason: EDA_CHECKLIST_REASONS.ENTRY_STATUS_INVALID }]);
  });
});
