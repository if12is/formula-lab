/**
 * Compliance rules DSL (N-01). Spec: plan/02-specs/rules-dsl.md.
 * A rule set is pure data: nothing regulatory is hard-coded in the engine. Every rule carries its
 * source (or, in a draft rule set only, an explicit `pendingSource` flag). Limits are decimal strings.
 * Message keys (not Arabic text) are mapped to wording by the apps.
 */
import { canonicalJson, sha256Hex } from '../hash';
import { isDecimalString } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { isUnit, type Unit } from '../core/quantity';
import type { MissingData } from '../contract';

export const OPERATORS = ['<=', '<', '>=', '>', '==', 'forbidden', 'required'] as const;
export type Operator = (typeof OPERATORS)[number];

export interface RuleScope {
  productTypes: string[];
  rinseOff?: boolean;
  ifraCategory?: string;
}

/** Exactly one key. Substance, INCI and function targets are concentrations (%w/w) in the finished product. */
export interface RuleTarget {
  substanceCas?: string;
  inci?: string;
  function?: string;
  materialId?: string;
  field?: string;
}

export interface RuleSource {
  sourceId: string;
  title: string;
  ref: string;
}

export interface Rule {
  id: string;
  version: string;
  scope: RuleScope;
  target: RuleTarget;
  operator: Operator;
  /** Decimal string; null only for `forbidden` and `required`. */
  limit: string | null;
  unit: Unit;
  /** Rules sharing a group id are summed: sum(c_i / limit_i) <= 1 (CosIng Annex III style). */
  group?: string;
  /** null only with `pendingSource: true`, and only inside a draft rule set. */
  source: RuleSource | null;
  pendingSource?: boolean;
  messageKey: string;
  /** Added to the label output when the rule's substance (CAS or INCI target) is present (N-06 hook). */
  labelWarningKey?: string;
}

export type RuleSetStatus = 'draft' | 'published';

export interface RuleSet {
  id: string;
  version: string;
  status: RuleSetStatus;
  rules: Rule[];
}

export const DSL_REASONS = {
  JS_NUMBER: 'dsl.js_number_rejected',
  NOT_OBJECT: 'dsl.not_object',
  UNKNOWN_KEY: 'dsl.unknown_key',
  REQUIRED: 'dsl.required',
  INVALID_TYPE: 'dsl.invalid_type',
  INVALID_OPERATOR: 'dsl.invalid_operator',
  INVALID_UNIT: 'dsl.invalid_unit',
  INVALID_LIMIT: 'dsl.invalid_limit',
  LIMIT_MUST_BE_NULL: 'dsl.limit_must_be_null',
  TARGET_EXACTLY_ONE: 'dsl.target_exactly_one',
  SOURCE_MISSING: 'dsl.source_missing',
  PENDING_ONLY_IN_DRAFT: 'dsl.pending_source_only_in_draft',
  PENDING_WITH_SOURCE: 'dsl.pending_source_with_source',
  DUPLICATE_RULE_ID: 'dsl.duplicate_rule_id',
  GROUP_INVALID: 'dsl.group_invalid',
  CONCENTRATION_UNIT: 'dsl.concentration_unit_must_be_pct_ww',
  LABEL_WARNING_TARGET: 'dsl.label_warning_needs_substance_target',
} as const;

const RULESET_KEYS = ['id', 'version', 'status', 'rules'];
const RULE_KEYS = ['id', 'version', 'scope', 'target', 'operator', 'limit', 'unit', 'group', 'source', 'pendingSource', 'messageKey', 'labelWarningKey'];
const SCOPE_KEYS = ['productTypes', 'rinseOff', 'ifraCategory'];
const TARGET_KEYS = ['substanceCas', 'inci', 'function', 'materialId', 'field'];
const SOURCE_KEYS = ['sourceId', 'title', 'ref'];

type Issues = MissingData[];

function isRecord(x: unknown): x is Record<string, unknown> {
  return typeof x === 'object' && x !== null && !Array.isArray(x);
}

function nonEmptyString(x: unknown): x is string {
  return typeof x === 'string' && x.trim() !== '';
}

function containsJsNumber(x: unknown): boolean {
  if (typeof x === 'number') {
    return true;
  }
  if (Array.isArray(x)) {
    return x.some(containsJsNumber);
  }
  return isRecord(x) && Object.values(x).some(containsJsNumber);
}

function checkKeys(obj: Record<string, unknown>, allowed: readonly string[], path: string, issues: Issues): void {
  for (const key of Object.keys(obj)) {
    if (!allowed.includes(key)) {
      issues.push({ property: `${path}.${key}`, reason: DSL_REASONS.UNKNOWN_KEY });
    }
  }
}

function requireString(obj: Record<string, unknown>, key: string, path: string, issues: Issues): void {
  const value = obj[key];
  if (value === undefined) {
    issues.push({ property: `${path}.${key}`, reason: DSL_REASONS.REQUIRED });
  } else if (!nonEmptyString(value)) {
    issues.push({ property: `${path}.${key}`, reason: DSL_REASONS.INVALID_TYPE });
  }
}

function optionalString(obj: Record<string, unknown>, key: string, path: string, issues: Issues): void {
  if (obj[key] !== undefined && !nonEmptyString(obj[key])) {
    issues.push({ property: `${path}.${key}`, reason: DSL_REASONS.INVALID_TYPE });
  }
}

function validateScope(scope: unknown, path: string, issues: Issues): void {
  if (!isRecord(scope)) {
    issues.push({ property: path, reason: scope === undefined ? DSL_REASONS.REQUIRED : DSL_REASONS.NOT_OBJECT });
    return;
  }
  checkKeys(scope, SCOPE_KEYS, path, issues);
  const types = scope.productTypes;
  if (!Array.isArray(types) || types.length === 0 || !types.every(nonEmptyString)) {
    issues.push({ property: `${path}.productTypes`, reason: types === undefined ? DSL_REASONS.REQUIRED : DSL_REASONS.INVALID_TYPE });
  }
  if (scope.rinseOff !== undefined && typeof scope.rinseOff !== 'boolean') {
    issues.push({ property: `${path}.rinseOff`, reason: DSL_REASONS.INVALID_TYPE });
  }
  optionalString(scope, 'ifraCategory', path, issues);
}

function validateTarget(target: unknown, path: string, issues: Issues): void {
  if (!isRecord(target)) {
    issues.push({ property: path, reason: target === undefined ? DSL_REASONS.REQUIRED : DSL_REASONS.NOT_OBJECT });
    return;
  }
  checkKeys(target, TARGET_KEYS, path, issues);
  const present = TARGET_KEYS.filter((k) => target[k] !== undefined);
  if (present.length !== 1) {
    issues.push({ property: path, reason: DSL_REASONS.TARGET_EXACTLY_ONE });
  }
  for (const key of present) {
    optionalString(target, key, path, issues);
  }
}

function validateSource(rule: Record<string, unknown>, status: unknown, path: string, issues: Issues): void {
  const source = rule.source;
  const pending = rule.pendingSource;
  if (pending !== undefined && typeof pending !== 'boolean') {
    issues.push({ property: `${path}.pendingSource`, reason: DSL_REASONS.INVALID_TYPE });
    return;
  }
  if (source === null || source === undefined) {
    if (pending !== true) {
      issues.push({ property: `${path}.source`, reason: DSL_REASONS.SOURCE_MISSING });
    } else if (status !== 'draft') {
      issues.push({ property: `${path}.pendingSource`, reason: DSL_REASONS.PENDING_ONLY_IN_DRAFT });
    }
    return;
  }
  if (pending === true) {
    issues.push({ property: `${path}.pendingSource`, reason: DSL_REASONS.PENDING_WITH_SOURCE });
  }
  if (!isRecord(source)) {
    issues.push({ property: `${path}.source`, reason: DSL_REASONS.NOT_OBJECT });
    return;
  }
  checkKeys(source, SOURCE_KEYS, `${path}.source`, issues);
  for (const key of SOURCE_KEYS) {
    requireString(source, key, `${path}.source`, issues);
  }
}

function validateRule(rule: unknown, status: unknown, path: string, issues: Issues): void {
  if (!isRecord(rule)) {
    issues.push({ property: path, reason: DSL_REASONS.NOT_OBJECT });
    return;
  }
  checkKeys(rule, RULE_KEYS, path, issues);
  requireString(rule, 'id', path, issues);
  requireString(rule, 'version', path, issues);
  requireString(rule, 'messageKey', path, issues);
  optionalString(rule, 'group', path, issues);
  optionalString(rule, 'labelWarningKey', path, issues);
  validateScope(rule.scope, `${path}.scope`, issues);
  validateTarget(rule.target, `${path}.target`, issues);
  validateSource(rule, status, path, issues);

  const operator = rule.operator;
  const operatorOk = typeof operator === 'string' && (OPERATORS as readonly string[]).includes(operator);
  if (!operatorOk) {
    issues.push({ property: `${path}.operator`, reason: DSL_REASONS.INVALID_OPERATOR });
  }
  if (!isUnit(rule.unit)) {
    issues.push({ property: `${path}.unit`, reason: DSL_REASONS.INVALID_UNIT });
  }
  const limit = rule.limit;
  const noLimit = operator === 'forbidden' || operator === 'required';
  if (operatorOk && noLimit && limit !== null) {
    issues.push({ property: `${path}.limit`, reason: DSL_REASONS.LIMIT_MUST_BE_NULL });
  } else if (operatorOk && !noLimit && !isDecimalString(limit)) {
    issues.push({ property: `${path}.limit`, reason: DSL_REASONS.INVALID_LIMIT });
  }

  const target = isRecord(rule.target) ? rule.target : {};
  const isConcentration = target.field === undefined;
  if (isConcentration && isUnit(rule.unit) && rule.unit !== '%w/w') {
    issues.push({ property: `${path}.unit`, reason: DSL_REASONS.CONCENTRATION_UNIT });
  }
  if (rule.group !== undefined) {
    const positive = isDecimalString(limit) && !/^-?0+(\.0+)?$/.test(limit) && !limit.startsWith('-');
    if (operator !== '<=' || !positive || !isConcentration) {
      issues.push({ property: `${path}.group`, reason: DSL_REASONS.GROUP_INVALID });
    }
  }
  if (rule.labelWarningKey !== undefined && target.substanceCas === undefined && target.inci === undefined) {
    issues.push({ property: `${path}.labelWarningKey`, reason: DSL_REASONS.LABEL_WARNING_TARGET });
  }
}

/**
 * Strict validation of an untrusted rule set (parsed JSON). Rejects JS numbers anywhere, unknown keys,
 * unknown operators/units, a missing source and `pendingSource` outside a draft rule set.
 * Every problem is reported (path in `property`, DSL reason key in `reason`).
 */
export function validateRuleSet(input: unknown): Result<RuleSet> {
  const issues: Issues = [];
  if (containsJsNumber(input)) {
    return missing([{ property: '$', reason: DSL_REASONS.JS_NUMBER }]);
  }
  if (!isRecord(input)) {
    return missing([{ property: '$', reason: DSL_REASONS.NOT_OBJECT }]);
  }
  checkKeys(input, RULESET_KEYS, '$', issues);
  requireString(input, 'id', '$', issues);
  requireString(input, 'version', '$', issues);
  if (input.status !== 'draft' && input.status !== 'published') {
    issues.push({ property: '$.status', reason: input.status === undefined ? DSL_REASONS.REQUIRED : DSL_REASONS.INVALID_TYPE });
  }
  if (!Array.isArray(input.rules)) {
    issues.push({ property: '$.rules', reason: input.rules === undefined ? DSL_REASONS.REQUIRED : DSL_REASONS.INVALID_TYPE });
  } else {
    const seen = new Set<string>();
    input.rules.forEach((rule: unknown, i: number) => {
      validateRule(rule, input.status, `$.rules[${i}]`, issues);
      const id = isRecord(rule) ? rule.id : undefined;
      if (typeof id === 'string') {
        if (seen.has(id)) {
          issues.push({ property: `$.rules[${i}].id`, reason: DSL_REASONS.DUPLICATE_RULE_ID });
        }
        seen.add(id);
      }
    });
  }
  return issues.length > 0 ? missing(issues) : ok(input as unknown as RuleSet);
}

/** Version hash of a rule set: sha256 over the canonical JSON (key order and whitespace do not matter). */
export function hashRuleSet(ruleSet: RuleSet): string {
  return sha256Hex(canonicalJson(ruleSet));
}
