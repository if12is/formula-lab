/**
 * Decision evaluator (Y-01). Pure and deterministic: same rule set + same context => same result and hashes.
 * A missing context field never passes: the condition fails with reason `missing`.
 * Rules are reported in rule-set order (independent of context key order).
 */
import { canonicalHash } from '../core/canonical-hash';
import { dec, isDecimalString } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { containsJsNumber, isRecord } from '../core/validate';
import {
  COMPOSITE_OPERATORS,
  hashDecisionRuleSet,
  validateDecisionRuleSet,
  type Condition,
  type DecisionRule,
  type DecisionRuleSet,
  type LeafCondition,
  type Severity,
} from './dsl';

/** Values a decision context may hold. Decimals travel as strings; absent = undefined or null. */
export type ContextValue = string | boolean | string[] | { [key: string]: ContextValue | undefined } | null;
export type DecisionContext = { [key: string]: ContextValue | undefined };

export type Decision = 'allowed' | 'blocked' | 'allowed_with_warnings';
export type FailureReason = 'unmet' | 'missing' | 'invalid';

export interface FailedRule {
  ruleId: string;
  severity: Severity;
  messageKey: string;
  params: Record<string, string>;
  remedyKey: string;
  /** `missing`: a needed context field is absent; `invalid`: wrong type for the operator; `unmet`: value fails the requirement. */
  reason: FailureReason;
  /** Sorted context paths that were absent (only for reason `missing`). */
  missingFields: string[];
}

export interface DecisionResult {
  decision: Decision;
  failed: FailedRule[];
  passed: string[];
  ruleSetHash: string;
  contextHash: string;
}

export const DECISION_REASONS = {
  RULESET_INVALID: 'decision.ruleset_invalid',
  CONTEXT_NOT_OBJECT: 'decision.context_not_object',
  CONTEXT_JS_NUMBER: 'decision.context_js_number_rejected',
  CONTEXT_INVALID_VALUE: 'decision.context_invalid_value',
} as const;

type State = 'pass' | 'fail' | 'missing' | 'invalid';
interface Outcome {
  state: State;
  missingFields: string[];
}

const PASS: Outcome = { state: 'pass', missingFields: [] };
const FAIL: Outcome = { state: 'fail', missingFields: [] };

type Lookup = { found: true; value: ContextValue } | { found: false };

function lookup(context: DecisionContext, path: string): Lookup {
  let current: unknown = context;
  for (const segment of path.split('.')) {
    if (!isRecord(current)) {
      return { found: false };
    }
    current = current[segment];
  }
  return current === undefined || current === null ? { found: false } : { found: true, value: current as ContextValue };
}

function isPlain(x: object): boolean {
  const proto = Object.getPrototypeOf(x) as unknown;
  return proto === Object.prototype || proto === null;
}

function validContextValue(x: unknown): boolean {
  if (x === undefined || x === null || typeof x === 'string' || typeof x === 'boolean') {
    return true;
  }
  if (Array.isArray(x)) {
    return x.every((e) => typeof e === 'string');
  }
  return isRecord(x) && isPlain(x) && Object.values(x).every(validContextValue);
}

function compareScalars(a: string | boolean, b: string | boolean): number | null {
  if (typeof a === 'boolean' || typeof b === 'boolean') {
    return a === b ? 0 : null;
  }
  if (isDecimalString(a) && isDecimalString(b)) {
    return dec(a).cmp(dec(b));
  }
  return a === b ? 0 : null;
}

function evaluateComparison(leaf: LeafCondition, actual: ContextValue, expected: ContextValue): State {
  if (Array.isArray(actual) || isRecord(actual) || Array.isArray(expected) || isRecord(expected)) {
    return 'invalid';
  }
  const a = actual as string | boolean;
  const b = expected as string | boolean;
  if (leaf.operator === 'eq' || leaf.operator === 'ne') {
    const equal = compareScalars(a, b) === 0;
    return equal === (leaf.operator === 'eq') ? 'pass' : 'fail';
  }
  if (!isDecimalString(a) || !isDecimalString(b)) {
    return 'invalid';
  }
  const c = dec(a).cmp(dec(b));
  const holds = { lt: c < 0, lte: c <= 0, gt: c > 0, gte: c >= 0 }[leaf.operator as 'lt' | 'lte' | 'gt' | 'gte'];
  return holds ? 'pass' : 'fail';
}

function evaluateMembership(leaf: LeafCondition, actual: ContextValue): State {
  const list = leaf.value as string[];
  const items = typeof actual === 'string' ? [actual] : Array.isArray(actual) ? actual : null;
  if (items === null) {
    return 'invalid';
  }
  // Arrays: `in` needs every element in the list (vacuously true when empty); `notIn` needs none.
  const holds = leaf.operator === 'in' ? items.every((i) => list.includes(i)) : items.every((i) => !list.includes(i));
  return holds ? 'pass' : 'fail';
}

function evaluateLeaf(leaf: LeafCondition, context: DecisionContext): Outcome {
  const subject = lookup(context, leaf.field);
  if (leaf.operator === 'exists') {
    return subject.found ? PASS : FAIL;
  }
  if (leaf.operator === 'notExists') {
    return subject.found ? FAIL : PASS;
  }
  const needed = [leaf.field];
  let expected: ContextValue | undefined = leaf.value;
  if (leaf.valueFrom !== undefined) {
    const other = lookup(context, leaf.valueFrom);
    needed.push(leaf.valueFrom);
    expected = other.found ? other.value : undefined;
  }
  const absent = needed.filter((p) => !lookup(context, p).found).sort();
  if (!subject.found || expected === undefined) {
    return { state: 'missing', missingFields: absent };
  }
  const state =
    leaf.operator === 'in' || leaf.operator === 'notIn'
      ? evaluateMembership(leaf, subject.value)
      : evaluateComparison(leaf, subject.value, expected);
  return { state, missingFields: [] };
}

function unionMissing(outcomes: Outcome[]): string[] {
  return [...new Set(outcomes.flatMap((o) => o.missingFields))].sort();
}

function evaluateCondition(c: Condition, context: DecisionContext): Outcome {
  if (!(COMPOSITE_OPERATORS as readonly string[]).includes(c.operator)) {
    return evaluateLeaf(c as LeafCondition, context);
  }
  const children = (c as { conditions: Condition[] }).conditions.map((child) => evaluateCondition(child, context));
  const has = (s: State) => children.some((o) => o.state === s);
  const gaps = (state: State): Outcome => ({ state, missingFields: unionMissing(children) });
  const undecided = has('missing') || has('invalid');
  switch (c.operator) {
    case 'all':
      return has('fail') ? FAIL : undecided ? gaps(has('invalid') ? 'invalid' : 'missing') : PASS;
    case 'any':
      return has('pass') ? PASS : undecided ? gaps(has('missing') ? 'missing' : 'invalid') : FAIL;
    default: // none: no child may hold
      return has('pass') ? FAIL : undecided ? gaps(has('invalid') ? 'invalid' : 'missing') : PASS;
  }
}

function echoParams(rule: DecisionRule, context: DecisionContext): Record<string, string> {
  const out: Record<string, string> = {};
  const entries = Object.entries(rule.params ?? {}).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0));
  for (const [name, path] of entries) {
    const found = lookup(context, path);
    if (!found.found || isRecord(found.value)) {
      continue;
    }
    const v = found.value;
    out[name] = Array.isArray(v) ? v.join(',') : String(v);
  }
  return out;
}

/** Evaluates every rule of the set against the context. Invalid rule set or context => Result missing. */
export function evaluateDecision(ruleSet: DecisionRuleSet, context: DecisionContext): Result<DecisionResult> {
  const valid = validateDecisionRuleSet(ruleSet);
  if (!valid.ok) {
    return missing(valid.missing.map((m) => ({ ...m, reason: `${DECISION_REASONS.RULESET_INVALID}:${m.reason}` })));
  }
  if (!isRecord(context)) {
    return missing([{ property: '$context', reason: DECISION_REASONS.CONTEXT_NOT_OBJECT }]);
  }
  if (containsJsNumber(context)) {
    return missing([{ property: '$context', reason: DECISION_REASONS.CONTEXT_JS_NUMBER }]);
  }
  if (!validContextValue(context)) {
    return missing([{ property: '$context', reason: DECISION_REASONS.CONTEXT_INVALID_VALUE }]);
  }
  const failed: FailedRule[] = [];
  const passed: string[] = [];
  for (const rule of ruleSet.rules) {
    const outcome = evaluateCondition(rule, context);
    if (outcome.state === 'pass') {
      passed.push(rule.id);
    } else {
      failed.push({
        ruleId: rule.id,
        severity: rule.severity,
        messageKey: rule.messageKey,
        params: echoParams(rule, context),
        remedyKey: rule.remedyKey,
        reason: outcome.state === 'fail' ? 'unmet' : outcome.state,
        missingFields: outcome.missingFields,
      });
    }
  }
  const decision: Decision = failed.some((f) => f.severity === 'block')
    ? 'blocked'
    : failed.length > 0
      ? 'allowed_with_warnings'
      : 'allowed';
  return ok({
    decision,
    failed,
    passed,
    ruleSetHash: hashDecisionRuleSet(ruleSet),
    contextHash: canonicalHash(context),
  });
}
