/* eslint-disable @typescript-eslint/no-explicit-any */
import { describe, expect, it } from 'vitest';
import registryJson from '../../data/product-families.json';
import templatesJson from '../../data/formula-templates/formula-templates.json';
import { ctx, materials, rule, ruleSet } from '../compliance/test-fixtures';
import { FAMILY_REASONS, familyByCode, familyLabRequirements, validateProductFamilyRegistry } from './registry';
import { TEMPLATE_REASONS, checkTemplateUse, instantiateTemplate, validateFormulaTemplates } from './templates';
import type { FormulaTemplate, ProductFamilyRegistry } from './types';

type Doc = any;
const clone = <T>(x: T): T => JSON.parse(JSON.stringify(x)) as T;
const reasons = (r: { ok: boolean; missing?: Array<{ reason: string }> }): string[] => (r.ok ? [] : (r.missing ?? []).map((m) => m.reason));

const registryResult = validateProductFamilyRegistry(registryJson);
const registry = (registryResult.ok ? registryResult.value : (undefined as never)) as ProductFamilyRegistry;
const templatesResult = validateFormulaTemplates(templatesJson, registry);

describe('the shipped registry (K-01)', () => {
  it('validates, and lists, first, the 24 families of K-02, K-04, K-05, K-06 and L-06 to L-08 (later phases append)', () => {
    expect(registryResult.ok).toBe(true);
    expect(registry.families.map((f) => f.code).slice(0, 24)).toEqual([
      'dish_liquid', 'laundry_powder', 'laundry_liquid', 'floor_cleaner', 'glass_cleaner', 'bathroom_cleaner', 'degreaser',
      'fabric_softener', 'laundry_fragrance',
      'shampoo', 'conditioner', 'hand_soap', 'shower_gel', 'bar_soap', 'face_wash',
      'alcohol_disinfectant', 'quat_disinfectant',
      'room_spray', 'aerosol_air_freshener', 'reed_diffuser', 'candle_wax_melt', 'gel_air_freshener', 'car_air_freshener', 'fabric_air_freshener',
    ]);
  });

  it('is only what plan/00-research says: EOS for detergents and air care, EDA for personal care and disinfectants, no tax regime guessed, nothing verified', () => {
    for (const f of registry.families) {
      expect(f.regulator).toBe(['detergents', 'air_care'].includes(f.group) ? 'EOS' : 'EDA');
      expect(f.taxRegime).toBeNull();
      expect(f.verification).toBe('pending');
      expect(f.confidence).toBe('L7_estimate');
    }
    expect(familyByCode(registry, 'glass_cleaner')?.ifraCategory).toBe('10B');
    expect(familyByCode(registry, 'shampoo')?.ifraCategory).toBe('9');
  });

  it('dish liquid needs the four calculations and three measurements the research names', () => {
    const dish = familyByCode(registry, 'dish_liquid');
    expect(dish?.requiredCalculations).toEqual(['active_matter', 'neutralization', 'salt_curve', 'litre_kilo']);
    expect(dish?.measuredProperties).toEqual(['viscosity', 'ph', 'cloud_point']);
  });

  it('splits what the lab can record from what it cannot yet (bulk density is named but not recordable)', () => {
    expect(familyLabRequirements(registry, 'laundry_powder')).toEqual({ lab: [], notYetRecordable: ['bulk_density', 'solubility'] });
    expect(familyLabRequirements(registry, 'bathroom_cleaner')).toEqual({ lab: ['ph'], notYetRecordable: ['corrosion'] });
    expect(familyLabRequirements(registry, 'glass_cleaner')).toEqual({ lab: [], notYetRecordable: [] });
    expect(familyLabRequirements(registry, 'nope')).toBeUndefined();
  });
});

describe('registry validation reports everything it refuses', () => {
  it.each([
    ['unknown calculation', (r: Doc) => { r.families[0].requiredCalculations.push('magic'); }, FAMILY_REASONS.CALCULATION_UNKNOWN],
    ['unknown measured property', (r: Doc) => { r.families[0].measuredProperties.push('magic'); }, FAMILY_REASONS.PROPERTY_UNKNOWN],
    ['unknown regulator', (r: Doc) => { r.families[0].regulator = 'FDA'; }, FAMILY_REASONS.REGULATOR_UNKNOWN],
    ['bad IFRA shape', (r: Doc) => { r.families[0].ifraCategory = 'ten'; }, FAMILY_REASONS.IFRA_INVALID],
    ['duplicate code', (r: Doc) => { r.families[1].code = r.families[0].code; }, FAMILY_REASONS.CODE_DUPLICATE],
    ['unknown source', (r: Doc) => { r.families[0].sourceId = 'x'; }, FAMILY_REASONS.SOURCE_UNKNOWN],
    ['a duplicated list entry', (r: Doc) => { r.families[0].measuredProperties.push('ph'); }, FAMILY_REASONS.LIST_DUPLICATE],
    ['verified on an estimate', (r: Doc) => { r.families[0].verification = 'verified'; }, FAMILY_REASONS.VERIFIED_NEEDS_SOURCE],
  ])('%s', (_label, change, reason) => {
    const doc = clone(registryJson) as Doc;
    change(doc);
    expect(reasons(validateProductFamilyRegistry(doc))).toContain(reason);
  });

  it('accepts null for a regulator, IFRA category and tax regime the sources do not state (never a guess)', () => {
    const doc = clone(registryJson) as Doc;
    doc.families[0].regulator = null;
    doc.families[0].ifraCategory = null;
    expect(validateProductFamilyRegistry(doc).ok).toBe(true);
  });

  it('a verified row is fine once its source is better than an estimate', () => {
    const doc = clone(registryJson) as Doc;
    doc.families[0].verification = 'verified';
    doc.families[0].confidence = 'L4_regulatory_db';
    expect(validateProductFamilyRegistry(doc).ok).toBe(true);
  });

  it('not an object => one clear reason, not a throw', () => {
    expect(reasons(validateProductFamilyRegistry(null))).toEqual([FAMILY_REASONS.NOT_OBJECT]);
  });
});

describe('the shipped template library (K-09)', () => {
  it('validates and has one template per family (but the skincare types the research does not structure), all theoretical, none with an invented percentage', () => {
    expect(templatesResult.ok).toBe(true);
    const lib = (templatesResult.ok ? templatesResult.value : undefined) as { templates: FormulaTemplate[] };
    const withoutTemplate = ['cream', 'sunscreen', 'mask', 'cleansing_cream', 'cleansing_foam'];
    expect(lib.templates.map((t) => t.familyCode)).toEqual(registry.families.map((f) => f.code).filter((code) => !withoutTemplate.includes(code)));
    for (const t of lib.templates) {
      expect(t.verification).toBe('theoretical');
      expect(t.lines.every((l) => l.percentWW === null)).toBe(true);
      expect(t.lines.filter((l) => l.qs).length).toBe(1);
    }
  });

  it.each([
    ['unknown family', (d: Doc) => { d.templates[0].familyCode = 'x'; }, TEMPLATE_REASONS.FAMILY_UNKNOWN],
    ['unknown role', (d: Doc) => { d.templates[0].lines[0].role = 'x'; }, TEMPLATE_REASONS.ROLE_UNKNOWN],
    ['two q.s. lines', (d: Doc) => { d.templates[0].lines[1].qs = true; }, TEMPLATE_REASONS.QS_MULTIPLE],
    ['a percentage on the q.s. line', (d: Doc) => { d.templates[0].lines[0].percentWW = '10'; }, TEMPLATE_REASONS.QS_HAS_PERCENT],
    ['a percentage of 0', (d: Doc) => { d.templates[0].lines[1].percentWW = '0'; }, TEMPLATE_REASONS.PERCENT_INVALID],
    ['a status that claims lab tests without numbers', (d: Doc) => { d.templates[0].verification = 'lab_tested'; d.templates[0].confidence = 'L1_coa_batch'; }, TEMPLATE_REASONS.STATUS_NEEDS_NUMBERS],
    ['chemist approval without an approver', (d: Doc) => { d.templates[0].verification = 'chemist_approved'; }, TEMPLATE_REASONS.APPROVAL_MISSING],
  ])('refuses %s', (_label, change, reason) => {
    const doc = clone(templatesJson) as Doc;
    change(doc);
    expect(reasons(validateFormulaTemplates(doc, registry))).toContain(reason);
  });
});

/** A small template with known numbers, for hand-computed use cases. */
const TEMPLATE: FormulaTemplate = {
  code: 't',
  familyCode: 'shampoo',
  name: { ar: 'ت', en: 'T' },
  verification: 'theoretical',
  sourceId: 'plan-00-research-05',
  confidence: 'L7_estimate',
  lines: [
    { key: 'water', role: 'water', percentWW: null, qs: true, optional: false },
    { key: 'anionic_surfactant', role: 'anionic_surfactant', percentWW: null, qs: false, optional: false },
    { key: 'preservative', role: 'preservative', percentWW: '0.5', qs: false, optional: false },
    { key: 'fragrance', role: 'fragrance', percentWW: null, qs: false, optional: true },
  ],
};

describe('instantiateTemplate', () => {
  it('balances q.s.: 12 + 0.5 (template) + 0.3 fragrance => water 87.2 and Σ 100', () => {
    const r = instantiateTemplate(TEMPLATE, {
      water: { materialId: 'W' },
      anionic_surfactant: { materialId: 'S', percentWW: '12' },
      preservative: { materialId: 'P' },
      fragrance: { materialId: 'F', percentWW: '0.3' },
    });
    expect(r.ok && r.value.lines.map((l) => [l.key, l.percentWW])).toEqual([['water', '87.2'], ['anionic_surfactant', '12'], ['preservative', '0.5'], ['fragrance', '0.3']]);
    expect(r.ok && r.value.sum).toBe('100');
    expect(r.ok && r.value.deviation).toBe('0');
  });

  it('an unfilled optional line is dropped: 12 + 0.5 => water 87.5', () => {
    const r = instantiateTemplate(TEMPLATE, { water: { materialId: 'W' }, anionic_surfactant: { materialId: 'S', percentWW: '12' }, preservative: { materialId: 'P' } });
    expect(r.ok && r.value.lines.map((l) => l.percentWW)).toEqual(['87.5', '12', '0.5']);
  });

  it('a caller percentage overrides the template one: preservative 0.5 -> 1 gives water 87', () => {
    const r = instantiateTemplate(TEMPLATE, { water: { materialId: 'W' }, anionic_surfactant: { materialId: 'S', percentWW: '12' }, preservative: { materialId: 'P', percentWW: '1' } });
    expect(r.ok && r.value.lines[0]?.percentWW).toBe('87');
  });

  it('missing material and missing percentage are all reported together, never zero', () => {
    const r = instantiateTemplate(TEMPLATE, { anionic_surfactant: { materialId: 'S' } });
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing).toEqual([
      { property: 'line:water', reason: TEMPLATE_REASONS.LINE_MATERIAL_MISSING },
      { materialId: 'S', property: 'line:anionic_surfactant', reason: TEMPLATE_REASONS.LINE_PERCENT_MISSING },
      { property: 'line:preservative', reason: TEMPLATE_REASONS.LINE_MATERIAL_MISSING },
    ]);
  });

  it('a share that leaves nothing for the q.s. line is refused (60 + 41 = 101)', () => {
    const r = instantiateTemplate(TEMPLATE, { water: { materialId: 'W' }, anionic_surfactant: { materialId: 'S', percentWW: '100' }, preservative: { materialId: 'P' } });
    expect(reasons(r)).toContain('formula.qs_balance_not_positive');
  });

  it('a line that is not in the template is refused', () => {
    expect(reasons(instantiateTemplate(TEMPLATE, { ghost: { materialId: 'X' } }))).toContain(TEMPLATE_REASONS.LINE_UNKNOWN);
  });

  it('the same material on two lines is refused', () => {
    const r = instantiateTemplate(TEMPLATE, { water: { materialId: 'W' }, anionic_surfactant: { materialId: 'W', percentWW: '12' }, preservative: { materialId: 'P' } });
    expect(reasons(r)).toContain('formula.duplicate_material');
  });
});

describe('checkTemplateUse: engine validation, then compliance', () => {
  // Fictional fixtures: M-A is 10% TEST-0001, M-B 2% TEST-0001, M-C none. Lines: M-A 5 + M-B 10 => TEST-0001 = 0.5 + 0.2 = 0.7.
  const t: FormulaTemplate = {
    ...TEMPLATE,
    lines: [
      { key: 'water', role: 'water', percentWW: null, qs: true, optional: false },
      { key: 'anionic_surfactant', role: 'anionic_surfactant', percentWW: null, qs: false, optional: false },
      { key: 'preservative', role: 'preservative', percentWW: null, qs: false, optional: false },
    ],
  };
  const fill = { water: { materialId: 'M-C' }, anionic_surfactant: { materialId: 'M-A', percentWW: '5' }, preservative: { materialId: 'M-B', percentWW: '10' } };
  const context = { ...ctx, productType: 'test_product' };

  it('PASS when the known limit holds (0.7 <= 1)', () => {
    const r = checkTemplateUse({ template: t, fill, materials: materials(), ruleSets: [ruleSet([rule({ id: 'r', limit: '1' })])], context });
    expect(r.ok && r.value.overall).toBe('PASS');
    expect(r.ok && r.value.instance.lines.map((l) => l.percentWW)).toEqual(['85', '5', '10']);
  });

  it('a compliance FAIL blocks the use (0.7 <= 0.5 fails)', () => {
    const r = checkTemplateUse({ template: t, fill, materials: materials(), ruleSets: [ruleSet([rule({ id: 'r', limit: '0.5' })])], context });
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing).toEqual([{ property: 'rule:r', reason: TEMPLATE_REASONS.COMPLIANCE_FAILED }]);
  });

  it('a material without a composition is UNKNOWN, reported but not a pass', () => {
    const r = checkTemplateUse({ template: t, fill, materials: materials().filter((m) => m.materialId !== 'M-A'), ruleSets: [ruleSet([rule({ id: 'r', limit: '1' })])], context });
    expect(r.ok && r.value.overall).toBe('UNKNOWN');
  });

  it('no rule set that applies => NOT_APPLICABLE, never PASS', () => {
    const r = checkTemplateUse({ template: t, fill, materials: materials(), ruleSets: [ruleSet([rule({ id: 'r', scope: { productTypes: ['other'] } })])], context });
    expect(r.ok && r.value.overall).toBe('NOT_APPLICABLE');
  });

  it('engine validation comes first: an unfilled line never reaches compliance', () => {
    const r = checkTemplateUse({ template: t, fill: { water: { materialId: 'M-C' } }, materials: materials(), ruleSets: [], context });
    expect(reasons(r)).toContain(TEMPLATE_REASONS.LINE_MATERIAL_MISSING);
  });
});

describe('K-04 / K-05 presets (what plan/00-research/05 states, nothing more)', () => {
  it('fabric softener: EOS, IFRA 10A, cationic active matter, viscosity and 4-week stability', () => {
    const f = familyByCode(registry, 'fabric_softener');
    expect([f?.regulator, f?.ifraCategory, f?.requiredCalculations, f?.measuredProperties]).toEqual(['EOS', '10A', ['active_matter'], ['viscosity', 'stability_4w']]);
  });

  it('laundry fragrance (booster): EOS and IFRA 10A from the air-care table; the research names no measurement for it', () => {
    const f = familyByCode(registry, 'laundry_fragrance');
    expect([f?.regulator, f?.ifraCategory, f?.measuredProperties]).toEqual(['EOS', '10A', []]);
  });

  it('disinfectants: EDA, no IFRA category stated (null), and the active-concentration calculation (the EDA path notice comes from the disinfectant flag, not a per-family note)', () => {
    for (const code of ['alcohol_disinfectant', 'quat_disinfectant']) {
      const f = familyByCode(registry, code);
      expect(f?.group).toBe('disinfectants');
      expect(f?.regulator).toBe('EDA');
      expect(f?.ifraCategory).toBeNull();
      expect(f?.requiredCalculations).toContain('disinfectant_active');
      expect(f?.notes).toBeUndefined();
    }
  });

  it('ethanol strength is named but the lab cannot record it yet (no %v/v lab property): shown as a gap, not demanded', () => {
    expect(familyLabRequirements(registry, 'alcohol_disinfectant')).toEqual({ lab: [], notYetRecordable: ['alcohol_strength'] });
    expect(familyLabRequirements(registry, 'quat_disinfectant')).toEqual({ lab: ['active_matter'], notYetRecordable: [] });
    expect(familyLabRequirements(registry, 'fabric_softener')).toEqual({ lab: ['viscosity', 'stability_4w'], notYetRecordable: [] });
  });

  it('every new template is structure only: shares null, one q.s. line, theoretical', () => {
    const lib = (templatesResult.ok ? templatesResult.value : undefined) as { templates: FormulaTemplate[] };
    const mine = lib.templates.filter((t) => ['fabric_softener', 'laundry_fragrance', 'alcohol_disinfectant', 'quat_disinfectant'].includes(t.familyCode));
    expect(mine).toHaveLength(4);
    for (const t of mine) {
      expect(t.verification).toBe('theoretical');
      expect(t.lines.every((l) => l.percentWW === null)).toBe(true);
    }
  });
});

describe('L-06 to L-08 air-care presets (plan/00-research/05 section 6, nothing more)', () => {
  it.each([
    ['room_spray', '10B'],
    ['aerosol_air_freshener', '10B'],
    ['reed_diffuser', '10A'],
    ['candle_wax_melt', '12'],
    ['fabric_air_freshener', '10B'],
  ])('%s: EOS, IFRA %s, no measurement named by the research', (code, ifra) => {
    const f = familyByCode(registry, code);
    expect([f?.group, f?.regulator, f?.ifraCategory, f?.measuredProperties]).toEqual(['air_care', 'EOS', ifra, []]);
  });

  it('gel and car freshener: the research says "12 or by type", so no single IFRA category is stated (null + note)', () => {
    for (const code of ['gel_air_freshener', 'car_air_freshener']) {
      const f = familyByCode(registry, code);
      expect([f?.ifraCategory, f?.notes]).toEqual([null, 'ifra_12_or_by_type']);
    }
  });

  it('aerosol: the fill-ratio calculation and the pressurised filling line note; candle: the wax load calculation', () => {
    const a = familyByCode(registry, 'aerosol_air_freshener');
    expect([a?.requiredCalculations, a?.notes]).toEqual([['fragrance', 'aerosol_fill'], 'aerosol_filling_line']);
    expect(familyByCode(registry, 'candle_wax_melt')?.requiredCalculations).toEqual(['fragrance', 'candle_load']);
  });

  it('every air-care template is structure only: shares null, theoretical, a propellant line only on the aerosol', () => {
    const lib = (templatesResult.ok ? templatesResult.value : undefined) as { templates: FormulaTemplate[] };
    const mine = lib.templates.filter((t) => registry.families.find((f) => f.code === t.familyCode)?.group === 'air_care');
    expect(mine).toHaveLength(7);
    for (const t of mine) {
      expect(t.verification).toBe('theoretical');
      expect(t.lines.every((l) => l.percentWW === null)).toBe(true);
      expect(t.lines.some((l) => l.role === 'propellant')).toBe(t.familyCode === 'aerosol_air_freshener');
    }
  });
});

describe('M-06 / M-09 skincare presets (plan/00-research/05 section 4, nothing more)', () => {
  const skincare = () => registry.families.filter((f) => f.group === 'skincare');

  it('lists the eleven product types of M-06 and M-09 (conditioner is the existing personal-care preset), all EDA, pending, estimate-grade', () => {
    expect(skincare().map((f) => f.code)).toEqual([
      'lotion', 'cream', 'face_cream', 'body_butter', 'moisturising_gel', 'hand_cream', 'hair_moisturiser', 'sunscreen', 'mask', 'cleansing_cream', 'cleansing_foam',
    ]);
    for (const f of skincare()) {
      expect([f.regulator, f.verification, f.confidence, f.taxRegime]).toEqual(['EDA', 'pending', 'L7_estimate', null]);
    }
  });

  it('IFRA is only what the research states: 5A body leave-on (lotion, body butter), 5B face (face cream), null everywhere else', () => {
    const ifra = Object.fromEntries(skincare().map((f) => [f.code, f.ifraCategory]));
    expect(ifra).toEqual({
      lotion: '5A', cream: null, face_cream: '5B', body_butter: '5A', moisturising_gel: null, hand_cream: null, hair_moisturiser: null,
      sunscreen: null, mask: null, cleansing_cream: null, cleansing_foam: null,
    });
  });

  it('the emulsion types carry the calculations and the measurements the research names; the four it does not name carry none and say so', () => {
    for (const code of ['lotion', 'cream', 'face_cream', 'body_butter', 'moisturising_gel', 'hand_cream', 'hair_moisturiser']) {
      const f = familyByCode(registry, code);
      expect(f?.requiredCalculations).toEqual(['phase_balance', 'hlb', 'preservative_limit']);
      expect(f?.measuredProperties).toEqual(['ph', 'viscosity', 'stability_4w', 'centrifuge_separation', 'thermal_cycle_separation']);
    }
    for (const code of ['sunscreen', 'mask', 'cleansing_cream', 'cleansing_foam']) {
      const f = familyByCode(registry, code);
      expect([f?.requiredCalculations, f?.measuredProperties, f?.notes]).toEqual([[], [], 'skincare_not_in_research']);
    }
  });

  it('the stability measurements are lab properties the lab loop records (M-07), the preservative challenge is not yet', () => {
    const recordable = registry.vocabulary.measuredProperties.filter((p) => ['centrifuge_separation', 'thermal_cycle_separation', 'stability_4w'].includes(p.id));
    expect(recordable.every((p) => p.labProperty === p.id)).toBe(true);
    expect(registry.vocabulary.measuredProperties.find((p) => p.id === 'preservative_challenge')?.labProperty).toBeNull();
    expect(registry.vocabulary.calculations.find((c) => c.id === 'preservative_limit')?.status).toBe('planned');
  });

  it('the six M-06 templates are structure only: shares null, theoretical, one q.s. line, water in every one that is water based', () => {
    const lib = (templatesResult.ok ? templatesResult.value : undefined) as { templates: FormulaTemplate[] };
    const mine = lib.templates.filter((t) => registry.families.find((f) => f.code === t.familyCode)?.group === 'skincare');
    expect(mine.map((t) => t.code)).toEqual(['body_lotion_basic', 'face_cream_basic', 'body_butter_basic', 'moisturising_gel_basic', 'hand_cream_basic', 'hair_moisturiser_basic']);
    for (const t of mine) {
      expect(t.verification).toBe('theoretical');
      expect(t.lines.every((l) => l.percentWW === null)).toBe(true);
      expect(t.lines.filter((l) => l.qs)).toHaveLength(1);
      expect(t.lines.some((l) => l.role === 'preservative')).toBe(true);
    }
    const water = (code: string) => lib.templates.find((t) => t.code === code)?.lines.find((l) => l.role === 'water');
    expect(['body_lotion_basic', 'face_cream_basic', 'moisturising_gel_basic', 'hand_cream_basic', 'hair_moisturiser_basic'].every((c) => water(c)?.qs === true)).toBe(true);
    expect(water('body_butter_basic')?.optional).toBe(true);
  });

  it('the hair moisturiser has a cationic conditioning agent line and no emulsifier is required on it (a cationic agent follows the TDS path, not HLB)', () => {
    const lib = (templatesResult.ok ? templatesResult.value : undefined) as { templates: FormulaTemplate[] };
    const hair = lib.templates.find((t) => t.code === 'hair_moisturiser_basic');
    expect(hair?.lines.some((l) => l.role === 'cationic_conditioning_agent' && !l.optional)).toBe(true);
    expect(hair?.lines.find((l) => l.role === 'emulsifier')?.optional).toBe(true);
  });
});
