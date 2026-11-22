/**
 * The formula template library (K-09). A template is a structure: lines by ROLE with a percentage only when a source gave one
 * (today none do, so every share is null and the water or filler line is q.s.). Nothing here invents a recipe.
 *
 * Using a template is a calculation like any other: the caller fills each line with a material (and a share where the template has
 * none), and the engine balances q.s., validates the formula (Σ = 100) and, when rule sets are given, runs the compliance engine over
 * the result. Anything not filled is reported in `missing[]`, never read as zero; a compliance FAIL blocks the use.
 */
import type { MissingData } from '../contract';
import { evaluateCompliance, overallStatus, type ComplianceReport } from '../compliance/evaluate';
import type { FormulaComposition, MaterialComposition } from '../compliance/composition';
import type { RuleSet } from '../compliance/dsl';
import type { ProductContext } from '../compliance/evaluate';
import { dec, isDecimalString } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { balanceQs, validateFormula } from '../formula';
import { CONFIDENCE_LEVELS, localizedNameIssue } from './registry';
import type { FormulaTemplate, FormulaTemplateLibrary, ProductFamilyRegistry } from './types';

export const TEMPLATE_REASONS = {
  NOT_OBJECT: 'families.template_not_object',
  CODE_INVALID: 'families.template_code_invalid',
  CODE_DUPLICATE: 'families.template_code_duplicate',
  NAME_MISSING: 'families.template_name_missing',
  FAMILY_UNKNOWN: 'families.template_family_unknown',
  VERIFICATION_INVALID: 'families.template_verification_invalid',
  NO_LINES: 'families.template_no_lines',
  LINE_KEY_INVALID: 'families.template_line_key_invalid',
  LINE_KEY_DUPLICATE: 'families.template_line_key_duplicate',
  ROLE_UNKNOWN: 'families.template_role_unknown',
  PERCENT_INVALID: 'families.template_percent_invalid',
  QS_MULTIPLE: 'families.template_qs_multiple',
  QS_HAS_PERCENT: 'families.template_qs_has_percent',
  SOURCE_UNKNOWN: 'families.template_source_unknown',
  CONFIDENCE_INVALID: 'families.template_confidence_invalid',
  STATUS_NEEDS_NUMBERS: 'families.template_status_needs_numbers',
  STATUS_NEEDS_SOURCE: 'families.template_status_needs_source',
  APPROVAL_MISSING: 'families.template_approval_missing',
  LINE_MATERIAL_MISSING: 'families.template_line_material_missing',
  LINE_PERCENT_MISSING: 'families.template_line_percent_missing',
  LINE_UNKNOWN: 'families.template_fill_line_unknown',
  COMPLIANCE_FAILED: 'families.template_compliance_failed',
} as const;

const KEY = /^[a-z][a-z0-9_]*$/;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const isRecord = (x: unknown): x is Record<string, unknown> => typeof x === 'object' && x !== null && !Array.isArray(x);

/** A template's percentages are all given on every line a use can not drop and that is not q.s. */
function hasAllNumbers(t: FormulaTemplate): boolean {
  return t.lines.every((l) => l.qs || l.optional || l.percentWW !== null);
}

export function validateFormulaTemplates(input: unknown, registry: ProductFamilyRegistry): Result<FormulaTemplateLibrary> {
  if (!isRecord(input) || !Array.isArray(input.templates)) {
    return missing([{ property: '$', reason: TEMPLATE_REASONS.NOT_OBJECT }]);
  }
  const gaps: MissingData[] = [];
  const templates = input.templates as Array<Record<string, unknown>>;
  const codes = templates.map((t) => String(t.code));
  const familyCodes = registry.families.map((f) => f.code);
  const sourceIds = registry.sources.map((s) => s.id);

  templates.forEach((t, index) => {
    const at = (field: string): string => `templates[${index}].${field}`;
    if (typeof t.code !== 'string' || !KEY.test(t.code)) {
      gaps.push({ property: at('code'), reason: TEMPLATE_REASONS.CODE_INVALID });
    } else if (codes.indexOf(t.code) !== index) {
      gaps.push({ property: at('code'), reason: TEMPLATE_REASONS.CODE_DUPLICATE });
    }
    if (localizedNameIssue(t.name)) {
      gaps.push({ property: at('name'), reason: TEMPLATE_REASONS.NAME_MISSING });
    }
    if (typeof t.familyCode !== 'string' || !familyCodes.includes(t.familyCode)) {
      gaps.push({ property: at('familyCode'), reason: TEMPLATE_REASONS.FAMILY_UNKNOWN });
    }
    if (typeof t.sourceId !== 'string' || !sourceIds.includes(t.sourceId)) {
      gaps.push({ property: at('sourceId'), reason: TEMPLATE_REASONS.SOURCE_UNKNOWN });
    }
    if (!(CONFIDENCE_LEVELS as readonly unknown[]).includes(t.confidence)) {
      gaps.push({ property: at('confidence'), reason: TEMPLATE_REASONS.CONFIDENCE_INVALID });
    }
    const verification = t.verification;
    if (verification !== 'theoretical' && verification !== 'lab_tested' && verification !== 'chemist_approved') {
      gaps.push({ property: at('verification'), reason: TEMPLATE_REASONS.VERIFICATION_INVALID });
    }
    const lines = Array.isArray(t.lines) ? (t.lines as Array<Record<string, unknown>>) : [];
    if (lines.length === 0) {
      gaps.push({ property: at('lines'), reason: TEMPLATE_REASONS.NO_LINES });
    }
    const keys = lines.map((l) => String(l.key));
    let qsCount = 0;
    lines.forEach((l, lineIndex) => {
      const lineAt = (field: string): string => `templates[${index}].lines[${lineIndex}].${field}`;
      if (typeof l.key !== 'string' || !KEY.test(l.key)) {
        gaps.push({ property: lineAt('key'), reason: TEMPLATE_REASONS.LINE_KEY_INVALID });
      } else if (keys.indexOf(l.key) !== lineIndex) {
        gaps.push({ property: lineAt('key'), reason: TEMPLATE_REASONS.LINE_KEY_DUPLICATE });
      }
      if (typeof l.role !== 'string' || !registry.vocabulary.roles.includes(l.role)) {
        gaps.push({ property: lineAt('role'), reason: TEMPLATE_REASONS.ROLE_UNKNOWN });
      }
      if (l.percentWW !== null) {
        if (!isDecimalString(l.percentWW) || dec(l.percentWW).lte(0) || dec(l.percentWW).gt(100)) {
          gaps.push({ property: lineAt('percentWW'), reason: TEMPLATE_REASONS.PERCENT_INVALID });
        }
      }
      if (l.qs === true) {
        qsCount += 1;
        if (l.percentWW !== null) {
          gaps.push({ property: lineAt('percentWW'), reason: TEMPLATE_REASONS.QS_HAS_PERCENT });
        }
      }
    });
    if (qsCount > 1) {
      gaps.push({ property: at('lines'), reason: TEMPLATE_REASONS.QS_MULTIPLE });
    }

    // A status above "theoretical" claims the numbers were checked: it needs the numbers, a source better than an estimate and a named approver.
    if (verification === 'lab_tested' || verification === 'chemist_approved') {
      const asTemplate = { lines: lines.map((l) => ({ qs: l.qs === true, optional: l.optional === true, percentWW: (l.percentWW ?? null) as string | null })) } as unknown as FormulaTemplate;
      if (!hasAllNumbers(asTemplate)) {
        gaps.push({ property: at('verification'), reason: TEMPLATE_REASONS.STATUS_NEEDS_NUMBERS });
      }
      if (t.confidence === 'L7_estimate') {
        gaps.push({ property: at('verification'), reason: TEMPLATE_REASONS.STATUS_NEEDS_SOURCE });
      }
    }
    if (verification === 'chemist_approved' && !(typeof t.approvedBy === 'string' && t.approvedBy.trim() !== '' && typeof t.approvedOn === 'string' && ISO_DATE.test(t.approvedOn))) {
      gaps.push({ property: at('approvedBy'), reason: TEMPLATE_REASONS.APPROVAL_MISSING });
    }
  });

  return gaps.length > 0 ? missing(gaps) : ok(input as unknown as FormulaTemplateLibrary);
}

/** What a caller supplies for one template line. */
export interface TemplateFill {
  materialId?: string;
  /** Needed where the template line carries no percentage (and the line is not q.s.). */
  percentWW?: string;
}

export interface FilledLine {
  key: string;
  role: string;
  materialId: string;
  percentWW: string;
  qs: boolean;
}

export interface TemplateInstance {
  lines: FilledLine[];
  /** The Σ of the balanced formula and its deviation from 100. */
  sum: string;
  deviation: string;
}

/**
 * Turns a template and the caller's fill into concrete lines: drops unfilled optional lines, balances the q.s. line and checks the
 * formula (Σ = 100, no duplicate material). Every unfilled or invalid line is reported together; nothing is defaulted.
 */
export function instantiateTemplate(template: FormulaTemplate, fill: Record<string, TemplateFill>): Result<TemplateInstance> {
  const gaps: MissingData[] = Object.keys(fill)
    .filter((key) => !template.lines.some((l) => l.key === key))
    .map((key) => ({ property: `line:${key}`, reason: TEMPLATE_REASONS.LINE_UNKNOWN }));

  const picked: Array<{ line: FormulaTemplate['lines'][number]; materialId: string; percentWW: string | undefined }> = [];
  for (const line of template.lines) {
    const given = fill[line.key] ?? {};
    const hasMaterial = typeof given.materialId === 'string' && given.materialId !== '';
    if (!hasMaterial && line.optional && given.percentWW === undefined) {
      continue;
    }
    if (!hasMaterial) {
      gaps.push({ property: `line:${line.key}`, reason: TEMPLATE_REASONS.LINE_MATERIAL_MISSING });
      continue;
    }
    // The template's own percentage wins only when the caller gave none: a chemist's number is the default, not a lock.
    const percent = line.qs ? undefined : (given.percentWW ?? line.percentWW ?? undefined);
    if (!line.qs && percent === undefined) {
      gaps.push({ materialId: given.materialId, property: `line:${line.key}`, reason: TEMPLATE_REASONS.LINE_PERCENT_MISSING });
      continue;
    }
    picked.push({ line, materialId: given.materialId as string, percentWW: percent });
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }

  const balanced = balanceQs(picked.map((p) => ({ materialId: p.materialId, ...(p.percentWW === undefined ? {} : { percentWW: p.percentWW }), qs: p.line.qs })));
  // A template without a q.s. line is checked as it stands (the caller's shares must already add up to 100).
  const hasQs = picked.some((p) => p.line.qs);
  const lines = hasQs ? balanced : { ok: true as const, value: { lines: picked.map((p) => ({ materialId: p.materialId, percentWW: p.percentWW as string })) }, warnings: [] as string[] };
  if (!lines.ok) {
    return missing(lines.errors.map((e) => ({ ...(e.materialId === undefined ? {} : { materialId: e.materialId }), property: 'formula', reason: e.messageKey })));
  }
  const checked = validateFormula(lines.value.lines);
  if (!checked.ok) {
    return missing(checked.errors.map((e) => ({ ...(e.materialId === undefined ? {} : { materialId: e.materialId }), property: 'formula', reason: e.messageKey })));
  }
  return ok({
    lines: picked.map((p, i) => ({ key: p.line.key, role: p.line.role, materialId: p.materialId, percentWW: (lines.value.lines[i] as { percentWW: string }).percentWW, qs: p.line.qs })),
    sum: checked.value.sum,
    deviation: checked.value.deviation,
  });
}

export interface TemplateUseInput {
  template: FormulaTemplate;
  fill: Record<string, TemplateFill>;
  /** The compositions of the materials used, by material id; a material absent from the list has an unknown composition. */
  materials: MaterialComposition[];
  /** The rule sets that apply to the family (already assembled). Empty = nothing to check against, reported as NOT_APPLICABLE. */
  ruleSets: RuleSet[];
  context: ProductContext;
}

export interface TemplateUseReport {
  instance: TemplateInstance;
  compliance: Array<{ ruleSetId: string; overall: ComplianceReport['overall'] | 'INVALID'; missing: MissingData[] }>;
  /** FAIL / UNKNOWN / PASS over the rule sets, or NOT_APPLICABLE when no rule set was given. */
  overall: 'PASS' | 'FAIL' | 'UNKNOWN' | 'NOT_APPLICABLE';
}

/**
 * Engine validation, then compliance, before a template becomes a formula. A failure to validate returns its `missing[]`; a
 * compliance FAIL returns `families.template_compliance_failed` with the failing rules. UNKNOWN does not block a draft (the approval
 * gates still need it resolved) but is reported; a PASS is never given for "nothing was checked".
 */
export function checkTemplateUse(input: TemplateUseInput): Result<TemplateUseReport> {
  const instance = instantiateTemplate(input.template, input.fill);
  if (!instance.ok) {
    return instance;
  }
  const composition: FormulaComposition = {
    lines: instance.value.lines.map((l) => ({ materialId: l.materialId, percentWW: l.percentWW })),
    materials: input.materials,
  };
  const compliance: TemplateUseReport['compliance'] = [];
  const failing: MissingData[] = [];
  for (const ruleSet of input.ruleSets) {
    const evaluated = evaluateCompliance(composition, ruleSet, input.context);
    if (!evaluated.ok) {
      compliance.push({ ruleSetId: ruleSet.id, overall: 'INVALID', missing: evaluated.missing });
      continue;
    }
    const applicable = evaluated.value.results.filter((r) => r.status !== 'NOT_APPLICABLE');
    // A set whose rules all lie outside this product decided nothing.
    if (applicable.length === 0) {
      continue;
    }
    compliance.push({ ruleSetId: ruleSet.id, overall: evaluated.value.overall, missing: evaluated.value.missing });
    for (const r of applicable.filter((x) => x.status === 'FAIL')) {
      failing.push({ property: `rule:${r.ruleId}`, reason: TEMPLATE_REASONS.COMPLIANCE_FAILED });
    }
  }
  if (failing.length > 0) {
    return missing(failing);
  }
  const statuses = compliance.map((c) => (c.overall === 'INVALID' ? 'UNKNOWN' : c.overall));
  return ok({ instance: instance.value, compliance, overall: statuses.length === 0 ? 'NOT_APPLICABLE' : overallStatus(statuses) });
}
