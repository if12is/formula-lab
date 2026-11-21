/**
 * The bridge between the compliance screens and the deterministic engine (@fl/engine). The browser never decides a
 * limit, a concentration or a status: every verdict comes out of evaluateCompliance, checkIfraCompliance and buildInciList,
 * and a rule is validated by validateRuleSet. This file only prepares what the engine takes and shapes what it gives back.
 *
 * What the engine takes about a formula is its lines and, per material, its sourced composition (the server builds that
 * shape in App\Services\Material\MaterialCompositionService::enginePayloads and sends it with each material).
 */
import { OPERATORS, UNITS, buildInciList, checkIfraCompliance, draftSummary, evaluateCompliance, hashRuleSet, isDecimalString, overallStatus, resolveValue, validateRuleSet } from '@fl/engine';
import { engineLines, normalizeDecimalText } from './engine';

/* ---- a formula as the compliance engine reads it ---------------------------------- */

/**
 * The state of the draft for compliance: 'empty' (no material yet), 'incomplete' (a line has no material or no usable
 * share, so the engine would be asked about numbers nobody has typed) or 'ready'.
 */
export function complianceState(lines) {
    if (!lines.some((line) => line.material_id)) {
        return 'empty';
    }

    const inputs = engineLines(lines);

    return lines.some((line) => !line.material_id) || inputs.some((line) => !isDecimalString(line.percentWW)) ? 'incomplete' : 'ready';
}

/**
 * The engine's FormulaComposition: the lines, and for every material on them its composition (absent when the
 * material has none, which the engine reports as UNKNOWN: a missing composition is never read as "contains nothing").
 */
export function formulaComposition(lines, materials) {
    const inputs = engineLines(lines);
    const ids = [...new Set(inputs.map((line) => line.materialId))];

    return {
        lines: inputs,
        materials: ids.map((materialId) => {
            const composition = materials[materialId]?.composition;

            return composition ? { materialId, composition } : { materialId };
        }),
    };
}

/**
 * What the listed components of a material add up to (engine draftSummary): { sum, deviation, withinTolerance, over }.
 * `over` is true when they add up to more than 100%, which the engine refuses to use (the composition is then UNKNOWN).
 */
export function compositionSummary(rows) {
    const summary = draftSummary(rows.map((row, index) => ({ materialId: `component-${index}`, percentWW: row.pct_ww })));

    return { sum: summary.sum, deviation: summary.deviation, withinTolerance: summary.withinTolerance, over: !summary.withinTolerance && !summary.deviation.startsWith('-') };
}

/** The rule sets that name the product type in some rule's scope: the ones preselected for a formula. */
export function ruleSetsForProductType(ruleSets, productType) {
    return ruleSets.filter((ruleSet) => ruleSet.productTypes.includes(productType));
}

/**
 * What every rule of one rule set says about the formula (engine evaluateCompliance).
 * Returns { ruleSet, report, errors, applicable, overall }:
 *   report      the engine's ComplianceReport, or null when the rule set itself is invalid
 *   errors      the engine's reasons when the rule set is invalid
 *   applicable  how many rules apply to this product (the others are NOT_APPLICABLE)
 *   overall     the report's overall status, or 'NOT_APPLICABLE' when no rule applies (the engine's overall of an empty
 *               list is PASS, which would say "compliant" about a formula nothing was checked against)
 */
export function evaluateRuleSet(composition, ruleSet, context) {
    const result = evaluateCompliance(composition, ruleSet.assembled, context);

    if (!result.ok) {
        return { ruleSet, report: null, errors: result.missing, applicable: 0, overall: 'UNKNOWN' };
    }

    const applicable = result.value.results.filter((rule) => rule.status !== 'NOT_APPLICABLE').length;

    return { ruleSet, report: result.value, errors: [], applicable, overall: applicable === 0 ? 'NOT_APPLICABLE' : result.value.overall };
}

/**
 * One status for several checks (engine overallStatus: any FAIL, else any UNKNOWN, else PASS). A check with nothing to
 * decide (NOT_APPLICABLE) is left out; with nothing left the answer is NOT_APPLICABLE, never PASS.
 */
export function combinedStatus(statuses) {
    const decided = statuses.filter((status) => status !== 'NOT_APPLICABLE');

    return decided.length === 0 ? 'NOT_APPLICABLE' : overallStatus(decided);
}

/** The limits of a fragrance's IFRA certificate by category: for each, the one value the engine's resolveValue picks. */
function limitsByCategory(rows) {
    const categories = [...new Set(rows.filter((row) => row.value !== null && row.value !== undefined).map((row) => row.category))];
    const limits = {};

    for (const category of categories) {
        const candidates = rows
            .filter((row) => row.category === category && row.value !== null && row.value !== undefined)
            .map((row) => ({ _id: row.id, value: row.value, unit: row.unit, sourceId: row.source_id, confidence: row.confidence, validFrom: row.valid_from }));
        const resolved = resolveValue('ifra_limit', candidates);

        // No single winner (a conflict at the top confidence) leaves the category without a limit: the engine says UNKNOWN.
        if (resolved.ok && resolved.value.chosenBy === 'rule') {
            const { value, unit, sourceId, confidence, validFrom } = resolved.value.chosen;
            limits[category] = { value, unit, sourceId, confidence, validFrom };
        }
    }

    return limits;
}

/**
 * The IFRA check of every fragrance oil in the formula against its own certificate (engine checkIfraCompliance).
 * Returns { report, fragrances } or null when no material of the formula is flagged as a fragrance oil: the engine's
 * overall of an empty list is PASS, which must never be shown for "nothing was checked".
 */
export function evaluateIfra(lines, materials, ifraCategory) {
    const inputs = engineLines(lines);
    const ids = [...new Set(inputs.map((line) => line.materialId))].filter((id) => materials[id]?.is_fragrance);

    if (ids.length === 0) {
        return null;
    }

    const fragrances = ids.map((materialId) => {
        const rows = materials[materialId].ifra_limits ?? [];

        return rows.length ? { materialId, certificate: { limitsByCategory: limitsByCategory(rows) } } : { materialId };
    });
    const category = (ifraCategory ?? '').trim();

    return { report: checkIfraCompliance({ ifraCategory: category === '' ? undefined : category, lines: inputs, fragrances, policy: {} }), fragrances: ids };
}

/**
 * The INCI list of a formula (engine buildInciList). With a rule set and the product type, rules that carry a label
 * warning add it when their substance is present. Returns the engine's Result: { ok, value: InciList } or { ok: false, missing }.
 */
export function inciList(lines, materials, { ruleSet = null, productType = null } = {}) {
    const composition = formulaComposition(lines, materials);

    return buildInciList(composition, ruleSet && productType ? { ruleSet: ruleSet.assembled, productContext: { productType } } : {});
}

/** Per material, why compliance cannot be decided: [{ materialId, reasons: [reason...] }], from the engine's `missing`. */
export function gapsByMaterial(missing) {
    const byMaterial = new Map();

    for (const gap of missing) {
        if (gap.materialId) {
            byMaterial.set(gap.materialId, [...new Set([...(byMaterial.get(gap.materialId) ?? []), gap.reason])]);
        }
    }

    return [...byMaterial].map(([materialId, reasons]) => ({ materialId, reasons }));
}

/** The gaps that belong to no material (a rule's scope, a product field), as reasons. */
export function generalGaps(missing) {
    return [...new Set(missing.filter((gap) => !gap.materialId).map((gap) => gap.reason))];
}

/**
 * An engine reason or warning as text. Reasons may carry details after a colon ("compliance.ruleset_invalid:dsl.source_missing",
 * "confidence.below_policy:<material>:<level>"); the first part is the message key and a second key, when it is one, is appended.
 */
export function reasonText(t, has, reason) {
    const [key, ...details] = reason.split(':');
    const head = has(`engine.${key}`) ? t(`engine.${key}`) : key;
    const extra = details.find((detail) => has(`engine.${detail}`));

    return extra ? `${head} ${t(`engine.${extra}`)}` : head;
}

/* ---- the rule form: JSON-free fields in, the engine's rules DSL out ------------------ */

export const TARGET_TYPES = ['substanceCas', 'inci', 'function', 'materialId', 'field'];
export { OPERATORS, UNITS };

/** An operator that compares with nothing: forbidden and required carry no limit. */
export const takesLimit = (operator) => operator !== 'forbidden' && operator !== 'required';

export function blankRuleForm() {
    return {
        id: '',
        productTypes: [],
        rinseOff: 'any',
        ifraCategory: '',
        targetType: 'substanceCas',
        targetValue: '',
        operator: '<=',
        limit: '',
        unit: '%w/w',
        group: '',
        sourceId: null,
        sourceTitle: '',
        sourceRef: '',
        pendingSource: false,
        message: '',
        active: true,
        labelWarningKey: '',
    };
}

/** A stored rule (its DSL JSON, and the row's wording and state) as the form's fields. */
export function ruleToForm(rule, { message = null, active = true } = {}) {
    const targetType = Object.keys(rule.target ?? {})[0] ?? 'substanceCas';

    return {
        id: rule.id,
        productTypes: [...(rule.scope?.productTypes ?? [])],
        rinseOff: rule.scope?.rinseOff === undefined ? 'any' : rule.scope.rinseOff ? 'yes' : 'no',
        ifraCategory: rule.scope?.ifraCategory ?? '',
        targetType,
        targetValue: rule.target?.[targetType] ?? '',
        operator: rule.operator,
        limit: rule.limit ?? '',
        unit: rule.unit,
        group: rule.group ?? '',
        sourceId: rule.source?.sourceId ?? null,
        sourceTitle: rule.source?.title ?? '',
        sourceRef: rule.source?.ref ?? '',
        pendingSource: Boolean(rule.pendingSource),
        message: message ?? '',
        active,
        labelWarningKey: rule.labelWarningKey ?? '',
    };
}

/**
 * The form's fields as one rule in the engine's rules DSL. Empty optional fields are left out, so the stored JSON (and
 * the rule set's hash) holds only what the person decided.
 */
export function buildRule(form, { version, messageKey }) {
    const scope = { productTypes: form.productTypes.map((type) => type.trim()).filter(Boolean) };

    if (form.rinseOff !== 'any') {
        scope.rinseOff = form.rinseOff === 'yes';
    }

    if (form.ifraCategory.trim() !== '') {
        scope.ifraCategory = form.ifraCategory.trim();
    }

    const hasSource = form.sourceId !== null && form.sourceId !== '';
    const rule = {
        id: form.id.trim(),
        version,
        scope,
        target: { [form.targetType]: form.targetValue.trim() },
        operator: form.operator,
        limit: takesLimit(form.operator) ? normalizeDecimalText(form.limit) : null,
        unit: form.unit,
        source: hasSource ? { sourceId: form.sourceId, title: form.sourceTitle, ref: form.sourceRef.trim() } : null,
        messageKey,
    };

    if (form.group.trim() !== '') {
        rule.group = form.group.trim();
    }

    if (!hasSource && form.pendingSource) {
        rule.pendingSource = true;
    }

    if (form.labelWarningKey) {
        rule.labelWarningKey = form.labelWarningKey;
    }

    return rule;
}

/** Form field each engine problem path belongs to, so the message lands under the right control. */
const FIELD_OF = [
    [/\.scope\.productTypes/, 'productTypes'],
    [/\.scope\.rinseOff/, 'rinseOff'],
    [/\.scope\.ifraCategory/, 'ifraCategory'],
    [/\.target/, 'targetValue'],
    [/\.operator$/, 'operator'],
    [/\.limit$/, 'limit'],
    [/\.unit$/, 'unit'],
    [/\.group$/, 'group'],
    [/\.(source|pendingSource)/, 'source'],
    [/\.id$/, 'id'],
];

/**
 * The engine's validator (validateRuleSet) on one rule, before it is sent. The rule is checked inside a draft rule set,
 * the status that lets a rule still wait for its source. Returns { valid, errors: { [formField]: [reason...] } };
 * a problem with no form field (a version, a message key, the rule set itself) is kept under `_other`.
 */
export function validateRuleDraft(rule) {
    const result = validateRuleSet({ id: 'rule-check', version: rule.version, status: 'draft', rules: [rule] });

    if (result.ok) {
        return { valid: true, errors: {} };
    }

    const errors = {};

    for (const issue of result.missing) {
        const field = FIELD_OF.find(([pattern]) => pattern.test(issue.property))?.[1] ?? '_other';
        (errors[field] ??= []).push(issue.reason);
    }

    return { valid: false, errors };
}

/**
 * Validation and hash of a whole rule set as it would be published (status `published`), with the engine's own code:
 * the same validator and hash the server runs when it publishes. `hash` is null when the rule set is invalid.
 */
export function publishPreview(assembled) {
    const ruleSet = { ...assembled, status: 'published' };
    const result = validateRuleSet(ruleSet);

    return result.ok ? { valid: true, issues: [], hash: hashRuleSet(result.value) } : { valid: false, issues: result.missing, hash: null };
}
