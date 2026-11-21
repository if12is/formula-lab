import { describe, expect, it } from 'vitest';
import { DECISION_DSL_REASONS as R, hashDecisionRuleSet, validateDecisionRuleSet, type DecisionRuleSet } from './dsl';

const base = () => ({
  id: 'rs',
  version: '1',
  status: 'draft',
  rules: [{ id: 'r1', operator: 'eq', field: 'a.b', value: '1', severity: 'block', messageKey: 'm', remedyKey: 'k' }],
});

function reasons(input: unknown): string[] {
  const r = validateDecisionRuleSet(input);
  return r.ok ? [] : r.missing.map((m) => `${m.property}=${m.reason}`);
}

function withRule(patch: Record<string, unknown>) {
  const s = base();
  s.rules = [{ ...s.rules[0], ...patch } as never];
  return s;
}

describe('validateDecisionRuleSet', () => {
  it('accepts a valid set', () => {
    expect(validateDecisionRuleSet(base()).ok).toBe(true);
  });

  it('rejects JS numbers anywhere', () => {
    expect(reasons(withRule({ value: 1 }))).toEqual(['$=' + R.JS_NUMBER]);
  });

  it('rejects non-objects and unknown keys', () => {
    expect(reasons('x')).toEqual(['$=' + R.NOT_OBJECT]);
    expect(reasons({ ...base(), extra: '1' })).toContain('$.extra=' + R.UNKNOWN_KEY);
    expect(reasons(withRule({ extra: '1' }))).toContain('$.rules[0].extra=' + R.UNKNOWN_KEY);
  });

  it('rejects unknown operators', () => {
    expect(reasons(withRule({ operator: 'between' }))).toEqual(['$.rules[0].operator=' + R.INVALID_OPERATOR]);
    expect(reasons(withRule({ operator: '<=' }))).toEqual(['$.rules[0].operator=' + R.INVALID_OPERATOR]);
  });

  it('checks set-level fields', () => {
    expect(reasons({ rules: [] })).toEqual(['$.id=' + R.REQUIRED, '$.version=' + R.REQUIRED, '$.status=' + R.REQUIRED]);
    expect(reasons({ id: '', version: '1', status: 'x', rules: 'no' })).toEqual(['$.id=' + R.INVALID_TYPE, '$.status=' + R.INVALID_TYPE, '$.rules=' + R.INVALID_TYPE]);
    expect(reasons({ id: 'a', version: '1', status: 'draft' })).toEqual(['$.rules=' + R.REQUIRED]);
  });

  it('checks rule-level reporting fields', () => {
    const s = withRule({ severity: 'info', messageKey: undefined, remedyKey: '', basis: ' ', params: { Bad: 'a.b' } });
    expect(reasons(s)).toEqual([
      '$.rules[0].messageKey=' + R.REQUIRED,
      '$.rules[0].remedyKey=' + R.INVALID_TYPE,
      '$.rules[0].basis=' + R.INVALID_TYPE,
      '$.rules[0].severity=' + R.INVALID_SEVERITY,
      '$.rules[0].params=' + R.INVALID_PARAMS,
    ]);
    expect(reasons(withRule({ severity: undefined }))).toEqual(['$.rules[0].severity=' + R.REQUIRED]);
    expect(reasons(withRule({ params: { ok: 'not a path!' } }))).toEqual(['$.rules[0].params=' + R.INVALID_PARAMS]);
    expect(validateDecisionRuleSet(withRule({ params: { ok: 'a.b' }, basis: 'plan' })).ok).toBe(true);
  });

  it('checks field paths', () => {
    expect(reasons(withRule({ field: 'a..b' }))).toEqual(['$.rules[0].field=' + R.INVALID_FIELD_PATH]);
    expect(reasons(withRule({ field: undefined }))).toEqual(['$.rules[0].field=' + R.REQUIRED]);
  });

  it('checks operand types per operator', () => {
    expect(reasons(withRule({ operator: 'lt', value: 'abc' }))).toEqual(['$.rules[0].value=' + R.INVALID_VALUE]);
    expect(validateDecisionRuleSet(withRule({ operator: 'lt', value: '1.5' })).ok).toBe(true);
    expect(reasons(withRule({ operator: 'in', value: [] }))).toEqual(['$.rules[0].value=' + R.INVALID_VALUE]);
    expect(reasons(withRule({ operator: 'notIn', value: 'a' }))).toEqual(['$.rules[0].value=' + R.INVALID_VALUE]);
    expect(validateDecisionRuleSet(withRule({ operator: 'in', value: ['a'] })).ok).toBe(true);
    expect(reasons(withRule({ operator: 'eq', value: ['a'] }))).toEqual(['$.rules[0].value=' + R.INVALID_VALUE]);
    expect(validateDecisionRuleSet(withRule({ operator: 'eq', value: true })).ok).toBe(true);
  });

  it('value / valueFrom exclusivity and valueFrom rules', () => {
    expect(reasons(withRule({ value: undefined }))).toEqual(['$.rules[0].value=' + R.VALUE_OR_VALUE_FROM]);
    expect(reasons(withRule({ valueFrom: 'c.d' }))).toEqual(['$.rules[0].value=' + R.VALUE_OR_VALUE_FROM]);
    expect(validateDecisionRuleSet(withRule({ value: undefined, valueFrom: 'c.d', operator: 'lte' })).ok).toBe(true);
    expect(reasons(withRule({ value: undefined, valueFrom: 'bad path', operator: 'lte' }))).toEqual(['$.rules[0].valueFrom=' + R.INVALID_FIELD_PATH]);
    expect(reasons(withRule({ value: undefined, valueFrom: 'c.d', operator: 'in' }))).toEqual(['$.rules[0].valueFrom=' + R.VALUE_NOT_ALLOWED]);
  });

  it('exists/notExists take no operand', () => {
    expect(validateDecisionRuleSet(withRule({ operator: 'exists', value: undefined })).ok).toBe(true);
    expect(reasons(withRule({ operator: 'notExists' }))).toEqual(['$.rules[0].value=' + R.VALUE_NOT_ALLOWED]);
    expect(reasons(withRule({ operator: 'exists', value: undefined, valueFrom: 'c.d' }))).toEqual(['$.rules[0].valueFrom=' + R.VALUE_NOT_ALLOWED]);
  });

  it('composites need conditions, no field/value, nested restrictions, unique ids', () => {
    const composite = (extra: Record<string, unknown>) =>
      withRule({ operator: 'all', field: undefined, value: undefined, conditions: [{ id: 'c1', operator: 'exists', field: 'x' }], ...extra });
    expect(validateDecisionRuleSet(composite({})).ok).toBe(true);
    expect(reasons(composite({ conditions: [] }))).toEqual(['$.rules[0].conditions=' + R.CONDITIONS_REQUIRED]);
    expect(reasons(composite({ conditions: undefined }))).toEqual(['$.rules[0].conditions=' + R.CONDITIONS_REQUIRED]);
    expect(reasons(composite({ field: 'a', value: '1', valueFrom: 'b' }))).toEqual([
      '$.rules[0].field=' + R.VALUE_NOT_ALLOWED,
      '$.rules[0].value=' + R.VALUE_NOT_ALLOWED,
      '$.rules[0].valueFrom=' + R.VALUE_NOT_ALLOWED,
    ]);
    expect(reasons(composite({ conditions: [{ id: 'c1', operator: 'exists', field: 'x', severity: 'block' }] }))).toEqual(['$.rules[0].conditions[0].severity=' + R.UNKNOWN_KEY]);
    expect(reasons(composite({ conditions: ['x'] }))).toEqual(['$.rules[0].conditions[0]=' + R.NOT_OBJECT]);
    expect(reasons(composite({ conditions: [{ id: 'r1', operator: 'exists', field: 'x' }] }))).toEqual(['$.rules[0].conditions[0].id=' + R.DUPLICATE_ID]);
    expect(reasons(withRule({ conditions: [{ id: 'z', operator: 'exists', field: 'x' }] }))).toEqual(['$.rules[0].conditions=' + R.VALUE_NOT_ALLOWED]);
  });

  it('rejects duplicate rule ids', () => {
    const s = base();
    s.rules.push({ ...s.rules[0] } as never);
    expect(reasons(s)).toEqual(['$.rules[1].id=' + R.DUPLICATE_ID]);
  });

  it('rejects non-object rules', () => {
    expect(reasons({ ...base(), rules: ['x'] })).toEqual(['$.rules[0]=' + R.NOT_OBJECT]);
  });
});

describe('hashDecisionRuleSet', () => {
  it('ignores key order and changes with content', () => {
    const a = base() as unknown as DecisionRuleSet;
    const b = JSON.parse(JSON.stringify({ rules: a.rules, status: a.status, version: a.version, id: a.id })) as DecisionRuleSet;
    expect(hashDecisionRuleSet(a)).toBe(hashDecisionRuleSet(b));
    expect(hashDecisionRuleSet(a)).toMatch(/^[0-9a-f]{64}$/);
    expect(hashDecisionRuleSet({ ...a, version: '2' })).not.toBe(hashDecisionRuleSet(a));
  });
});
