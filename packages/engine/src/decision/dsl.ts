/**
 * Decision DSL (Y-01). A decision rule set is pure data: a versioned, hashed list of requirements
 * (conditions) that a typed decision context must satisfy. Nothing domain-specific is hard-coded here.
 * Values are decimal strings, strings, booleans or string arrays (never JS numbers). Message and remedy
 * keys (not Arabic text) are mapped to wording by the apps.
 */
import { canonicalHash } from '../core/canonical-hash';
import { isDecimalString } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { checkKeys as checkKeysWith, containsJsNumber, isRecord, nonEmptyString, type Issues } from '../core/validate';

export const COMPARISON_OPERATORS = ['eq', 'ne', 'lt', 'lte', 'gt', 'gte'] as const;
export const MEMBERSHIP_OPERATORS = ['in', 'notIn'] as const;
export const PRESENCE_OPERATORS = ['exists', 'notExists'] as const;
export const COMPOSITE_OPERATORS = ['all', 'any', 'none'] as const;
export const DECISION_OPERATORS = [
  ...COMPARISON_OPERATORS,
  ...MEMBERSHIP_OPERATORS,
  ...PRESENCE_OPERATORS,
  ...COMPOSITE_OPERATORS,
] as const;
export type DecisionOperator = (typeof DECISION_OPERATORS)[number];

export type Severity = 'block' | 'warn';
export type DecisionRuleSetStatus = 'draft' | 'published';

/** Literal operand in a rule: decimal string, string, boolean or string list. */
export type ConditionValue = string | boolean | string[];

/** A condition is a REQUIREMENT: it passes when the context satisfies it. */
export interface LeafCondition {
  id: string;
  /** Dotted path into the decision context, e.g. `compliance.overall`. */
  field: string;
  operator: Exclude<DecisionOperator, (typeof COMPOSITE_OPERATORS)[number]>;
  /** Literal operand. Absent for exists/notExists, or when `valueFrom` is used. */
  value?: ConditionValue;
  /** Compare against another context field instead of a literal (e.g. a configured threshold). */
  valueFrom?: string;
}

export interface CompositeCondition {
  id: string;
  operator: (typeof COMPOSITE_OPERATORS)[number];
  conditions: Condition[];
}

export type Condition = LeafCondition | CompositeCondition;

/** Top-level rule: a condition plus what to report when it is not satisfied. */
export type DecisionRule = Condition & {
  severity: Severity;
  messageKey: string;
  /** Param name -> dotted context path echoed into the failure's params. */
  params?: Record<string, string>;
  /** The next step the user must take. */
  remedyKey: string;
  /** Where the requirement comes from (plan task, spec, standard). */
  basis?: string;
};

export interface DecisionRuleSet {
  id: string;
  version: string;
  status: DecisionRuleSetStatus;
  rules: DecisionRule[];
}

export const DECISION_DSL_REASONS = {
  JS_NUMBER: 'decision.dsl.js_number_rejected',
  NOT_OBJECT: 'decision.dsl.not_object',
  UNKNOWN_KEY: 'decision.dsl.unknown_key',
  REQUIRED: 'decision.dsl.required',
  INVALID_TYPE: 'decision.dsl.invalid_type',
  INVALID_OPERATOR: 'decision.dsl.invalid_operator',
  INVALID_FIELD_PATH: 'decision.dsl.invalid_field_path',
  INVALID_VALUE: 'decision.dsl.invalid_value',
  VALUE_NOT_ALLOWED: 'decision.dsl.value_not_allowed',
  VALUE_OR_VALUE_FROM: 'decision.dsl.value_or_value_from_exactly_one',
  INVALID_SEVERITY: 'decision.dsl.invalid_severity',
  INVALID_PARAMS: 'decision.dsl.invalid_params',
  CONDITIONS_REQUIRED: 'decision.dsl.conditions_required',
  DUPLICATE_ID: 'decision.dsl.duplicate_id',
} as const;

const RULESET_KEYS = ['id', 'version', 'status', 'rules'];
const NESTED_KEYS = ['id', 'field', 'operator', 'value', 'valueFrom', 'conditions'];
const RULE_KEYS = [...NESTED_KEYS, 'severity', 'messageKey', 'params', 'remedyKey', 'basis'];
const FIELD_PATH = /^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/;
const PARAM_NAME = /^[a-z][A-Za-z0-9_]*$/;

const isOperator = (x: unknown): x is DecisionOperator =>
  typeof x === 'string' && (DECISION_OPERATORS as readonly string[]).includes(x);
const isComposite = (op: DecisionOperator): boolean => (COMPOSITE_OPERATORS as readonly string[]).includes(op);
const isComparison = (op: DecisionOperator): boolean => (COMPARISON_OPERATORS as readonly string[]).includes(op);
const isOrdering = (op: DecisionOperator): boolean => isComparison(op) && op !== 'eq' && op !== 'ne';
const isMembership = (op: DecisionOperator): boolean => (MEMBERSHIP_OPERATORS as readonly string[]).includes(op);

export function isFieldPath(x: unknown): x is string {
  return typeof x === 'string' && FIELD_PATH.test(x);
}

function checkKeys(obj: Record<string, unknown>, allowed: readonly string[], path: string, issues: Issues): void {
  checkKeysWith(obj, allowed, path, issues, DECISION_DSL_REASONS.UNKNOWN_KEY);
}

function requireString(obj: Record<string, unknown>, key: string, path: string, issues: Issues): void {
  const value = obj[key];
  if (value === undefined) {
    issues.push({ property: `${path}.${key}`, reason: DECISION_DSL_REASONS.REQUIRED });
  } else if (!nonEmptyString(value)) {
    issues.push({ property: `${path}.${key}`, reason: DECISION_DSL_REASONS.INVALID_TYPE });
  }
}

function validateValue(op: DecisionOperator, value: unknown, path: string, issues: Issues): void {
  const bad = () => issues.push({ property: `${path}.value`, reason: DECISION_DSL_REASONS.INVALID_VALUE });
  if (isOrdering(op)) {
    if (!isDecimalString(value)) {
      bad();
    }
  } else if (isMembership(op)) {
    if (!Array.isArray(value) || value.length === 0 || !value.every(nonEmptyString)) {
      bad();
    }
  } else if (typeof value !== 'string' && typeof value !== 'boolean') {
    bad();
  }
}

function validateCondition(c: unknown, nested: boolean, path: string, ids: Set<string>, issues: Issues): void {
  if (!isRecord(c)) {
    issues.push({ property: path, reason: DECISION_DSL_REASONS.NOT_OBJECT });
    return;
  }
  checkKeys(c, nested ? NESTED_KEYS : RULE_KEYS, path, issues);
  requireString(c, 'id', path, issues);
  if (typeof c.id === 'string') {
    if (ids.has(c.id)) {
      issues.push({ property: `${path}.id`, reason: DECISION_DSL_REASONS.DUPLICATE_ID });
    }
    ids.add(c.id);
  }
  const op = c.operator;
  if (!isOperator(op)) {
    issues.push({ property: `${path}.operator`, reason: DECISION_DSL_REASONS.INVALID_OPERATOR });
    return;
  }
  if (isComposite(op)) {
    for (const key of ['field', 'value', 'valueFrom']) {
      if (c[key] !== undefined) {
        issues.push({ property: `${path}.${key}`, reason: DECISION_DSL_REASONS.VALUE_NOT_ALLOWED });
      }
    }
    if (!Array.isArray(c.conditions) || c.conditions.length === 0) {
      issues.push({ property: `${path}.conditions`, reason: DECISION_DSL_REASONS.CONDITIONS_REQUIRED });
    } else {
      c.conditions.forEach((child: unknown, i: number) => validateCondition(child, true, `${path}.conditions[${i}]`, ids, issues));
    }
    return;
  }
  if (c.conditions !== undefined) {
    issues.push({ property: `${path}.conditions`, reason: DECISION_DSL_REASONS.VALUE_NOT_ALLOWED });
  }
  if (!isFieldPath(c.field)) {
    issues.push({ property: `${path}.field`, reason: c.field === undefined ? DECISION_DSL_REASONS.REQUIRED : DECISION_DSL_REASONS.INVALID_FIELD_PATH });
  }
  if ((PRESENCE_OPERATORS as readonly string[]).includes(op)) {
    for (const key of ['value', 'valueFrom']) {
      if (c[key] !== undefined) {
        issues.push({ property: `${path}.${key}`, reason: DECISION_DSL_REASONS.VALUE_NOT_ALLOWED });
      }
    }
    return;
  }
  const hasValue = c.value !== undefined;
  const hasFrom = c.valueFrom !== undefined;
  if (hasValue === hasFrom) {
    issues.push({ property: `${path}.value`, reason: DECISION_DSL_REASONS.VALUE_OR_VALUE_FROM });
  } else if (hasValue) {
    validateValue(op, c.value, path, issues);
  } else if (!isComparison(op) || !isFieldPath(c.valueFrom)) {
    issues.push({ property: `${path}.valueFrom`, reason: isComparison(op) ? DECISION_DSL_REASONS.INVALID_FIELD_PATH : DECISION_DSL_REASONS.VALUE_NOT_ALLOWED });
  }
}

function validateRule(rule: unknown, path: string, ids: Set<string>, issues: Issues): void {
  validateCondition(rule, false, path, ids, issues);
  if (!isRecord(rule)) {
    return;
  }
  requireString(rule, 'messageKey', path, issues);
  requireString(rule, 'remedyKey', path, issues);
  if (rule.basis !== undefined && !nonEmptyString(rule.basis)) {
    issues.push({ property: `${path}.basis`, reason: DECISION_DSL_REASONS.INVALID_TYPE });
  }
  if (rule.severity !== 'block' && rule.severity !== 'warn') {
    issues.push({ property: `${path}.severity`, reason: rule.severity === undefined ? DECISION_DSL_REASONS.REQUIRED : DECISION_DSL_REASONS.INVALID_SEVERITY });
  }
  if (rule.params !== undefined) {
    const p = rule.params;
    const good = isRecord(p) && Object.entries(p).every(([k, v]) => PARAM_NAME.test(k) && isFieldPath(v));
    if (!good) {
      issues.push({ property: `${path}.params`, reason: DECISION_DSL_REASONS.INVALID_PARAMS });
    }
  }
}

/**
 * Strict validation of an untrusted decision rule set (parsed JSON). Rejects JS numbers anywhere, unknown keys,
 * unknown operators, malformed field paths, wrong operand types and duplicate ids. Reports every problem.
 */
export function validateDecisionRuleSet(input: unknown): Result<DecisionRuleSet> {
  if (containsJsNumber(input)) {
    return missing([{ property: '$', reason: DECISION_DSL_REASONS.JS_NUMBER }]);
  }
  if (!isRecord(input)) {
    return missing([{ property: '$', reason: DECISION_DSL_REASONS.NOT_OBJECT }]);
  }
  const issues: Issues = [];
  checkKeys(input, RULESET_KEYS, '$', issues);
  requireString(input, 'id', '$', issues);
  requireString(input, 'version', '$', issues);
  if (input.status !== 'draft' && input.status !== 'published') {
    issues.push({ property: '$.status', reason: input.status === undefined ? DECISION_DSL_REASONS.REQUIRED : DECISION_DSL_REASONS.INVALID_TYPE });
  }
  if (!Array.isArray(input.rules)) {
    issues.push({ property: '$.rules', reason: input.rules === undefined ? DECISION_DSL_REASONS.REQUIRED : DECISION_DSL_REASONS.INVALID_TYPE });
  } else {
    const ids = new Set<string>();
    input.rules.forEach((rule: unknown, i: number) => validateRule(rule, `$.rules[${i}]`, ids, issues));
  }
  return issues.length > 0 ? missing(issues) : ok(input as unknown as DecisionRuleSet);
}

/** Version hash of a decision rule set: sha256 over canonical JSON. */
export function hashDecisionRuleSet(ruleSet: DecisionRuleSet): string {
  return canonicalHash(ruleSet);
}
