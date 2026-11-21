import { describe, expect, it } from 'vitest';
import { DSL_REASONS, hashRuleSet, validateRuleSet } from './dsl';
import { rule, ruleSet } from './test-fixtures';

const reasons = (input: unknown): string[] => {
  const r = validateRuleSet(input);
  return r.ok ? [] : r.missing.map((m) => `${m.property}|${m.reason}`);
};
const clone = <T>(x: T): T => JSON.parse(JSON.stringify(x)) as T;

describe('validateRuleSet', () => {
  it('accepts a well-formed rule set', () => {
    const rs = ruleSet([rule({ id: 'a' }), rule({ id: 'b', operator: 'forbidden', limit: null, labelWarningKey: 'label.x', group: undefined })]);
    expect(validateRuleSet(rs).ok).toBe(true);
  });

  it('rejects JS numbers anywhere', () => {
    const rs = clone(ruleSet([rule({ id: 'a' })])) as unknown as { rules: { limit: unknown }[] };
    rs.rules[0]!.limit = 0.5;
    expect(reasons(rs)).toEqual([`$|${DSL_REASONS.JS_NUMBER}`]);
    expect(reasons({ ...ruleSet([]), version: 1 })).toEqual([`$|${DSL_REASONS.JS_NUMBER}`]);
  });

  it('rejects non-objects, unknown keys, unknown operators and units', () => {
    expect(reasons(null)).toEqual([`$|${DSL_REASONS.NOT_OBJECT}`]);
    expect(reasons([])).toEqual([`$|${DSL_REASONS.NOT_OBJECT}`]);
    expect(reasons({ ...ruleSet([]), extra: 'x' })).toContain(`$.extra|${DSL_REASONS.UNKNOWN_KEY}`);
    expect(reasons(ruleSet([rule({ id: 'a', operator: '<>' as never })]))).toContain(`$.rules[0].operator|${DSL_REASONS.INVALID_OPERATOR}`);
    expect(reasons(ruleSet([rule({ id: 'a', unit: 'ppm' as never })]))).toContain(`$.rules[0].unit|${DSL_REASONS.INVALID_UNIT}`);
    const extraRule = { ...rule({ id: 'a' }), foo: 'bar' };
    expect(reasons(ruleSet([extraRule]))).toContain(`$.rules[0].foo|${DSL_REASONS.UNKNOWN_KEY}`);
    expect(reasons({ ...ruleSet([rule({ id: 'a' })]), rules: [{ ...rule({ id: 'a' }), scope: { productTypes: ['x'], q: '1' } }] })).toContain(`$.rules[0].scope.q|${DSL_REASONS.UNKNOWN_KEY}`);
  });

  it('requires id, version, status, rules with the right types', () => {
    expect(reasons({})).toEqual([
      `$.id|${DSL_REASONS.REQUIRED}`,
      `$.version|${DSL_REASONS.REQUIRED}`,
      `$.status|${DSL_REASONS.REQUIRED}`,
      `$.rules|${DSL_REASONS.REQUIRED}`,
    ]);
    expect(reasons({ id: 'x', version: '1', status: 'weird', rules: 'no' })).toEqual([
      `$.status|${DSL_REASONS.INVALID_TYPE}`,
      `$.rules|${DSL_REASONS.INVALID_TYPE}`,
    ]);
    expect(reasons(ruleSet([null as never]))).toEqual([`$.rules[0]|${DSL_REASONS.NOT_OBJECT}`]);
  });

  it('requires a source; pendingSource only in a draft rule set and only with source null', () => {
    const noSource = rule({ id: 'a', source: null });
    expect(reasons(ruleSet([noSource]))).toEqual([`$.rules[0].source|${DSL_REASONS.SOURCE_MISSING}`]);
    const withoutKey: Record<string, unknown> = { ...rule({ id: 'a' }) };
    delete withoutKey.source;
    expect(reasons(ruleSet([withoutKey as never]))).toEqual([`$.rules[0].source|${DSL_REASONS.SOURCE_MISSING}`]);
    const pending = rule({ id: 'a', source: null, pendingSource: true });
    expect(reasons(ruleSet([pending], { status: 'published' }))).toEqual([`$.rules[0].pendingSource|${DSL_REASONS.PENDING_ONLY_IN_DRAFT}`]);
    expect(validateRuleSet(ruleSet([pending], { status: 'draft' })).ok).toBe(true);
    expect(reasons(ruleSet([rule({ id: 'a', pendingSource: true })], { status: 'draft' }))).toEqual([`$.rules[0].pendingSource|${DSL_REASONS.PENDING_WITH_SOURCE}`]);
    expect(reasons(ruleSet([rule({ id: 'a', pendingSource: 'yes' as never })]))).toEqual([`$.rules[0].pendingSource|${DSL_REASONS.INVALID_TYPE}`]);
    expect(reasons(ruleSet([rule({ id: 'a', source: 'x' as never })]))).toEqual([`$.rules[0].source|${DSL_REASONS.NOT_OBJECT}`]);
    expect(reasons(ruleSet([rule({ id: 'a', source: { sourceId: '', title: 't' } as never })]))).toEqual([
      `$.rules[0].source.sourceId|${DSL_REASONS.INVALID_TYPE}`,
      `$.rules[0].source.ref|${DSL_REASONS.REQUIRED}`,
    ]);
  });

  it('checks limits against operators', () => {
    expect(reasons(ruleSet([rule({ id: 'a', limit: null })]))).toEqual([`$.rules[0].limit|${DSL_REASONS.INVALID_LIMIT}`]);
    expect(reasons(ruleSet([rule({ id: 'a', limit: '1e2' })]))).toEqual([`$.rules[0].limit|${DSL_REASONS.INVALID_LIMIT}`]);
    expect(reasons(ruleSet([rule({ id: 'a', operator: 'forbidden', limit: '0' })]))).toEqual([`$.rules[0].limit|${DSL_REASONS.LIMIT_MUST_BE_NULL}`]);
    expect(validateRuleSet(ruleSet([rule({ id: 'a', operator: 'required', limit: null })])).ok).toBe(true);
  });

  it('target has exactly one key; concentration targets use %w/w', () => {
    expect(reasons(ruleSet([rule({ id: 'a', target: {} })]))).toEqual([`$.rules[0].target|${DSL_REASONS.TARGET_EXACTLY_ONE}`]);
    expect(reasons(ruleSet([rule({ id: 'a', target: { substanceCas: 'X', inci: 'Y' } })]))).toEqual([`$.rules[0].target|${DSL_REASONS.TARGET_EXACTLY_ONE}`]);
    expect(reasons(ruleSet([rule({ id: 'a', target: { substanceCas: 'X', bogus: 'Y' } as never })]))).toContain(`$.rules[0].target.bogus|${DSL_REASONS.UNKNOWN_KEY}`);
    expect(reasons(ruleSet([rule({ id: 'a', target: { substanceCas: '' } })]))).toEqual([`$.rules[0].target.substanceCas|${DSL_REASONS.INVALID_TYPE}`]);
    expect(reasons(ruleSet([rule({ id: 'a', unit: 'g' })]))).toEqual([`$.rules[0].unit|${DSL_REASONS.CONCENTRATION_UNIT}`]);
    expect(validateRuleSet(ruleSet([rule({ id: 'a', target: { field: 'pH' }, unit: 'g' })])).ok).toBe(true);
    expect(reasons(ruleSet([rule({ id: 'a', target: undefined as never })]))).toEqual([`$.rules[0].target|${DSL_REASONS.REQUIRED}`]);
    expect(reasons(ruleSet([rule({ id: 'a', target: 'x' as never })]))).toContain(`$.rules[0].target|${DSL_REASONS.NOT_OBJECT}`);
  });

  it('checks scope', () => {
    expect(reasons(ruleSet([rule({ id: 'a', scope: undefined as never })]))).toContain(`$.rules[0].scope|${DSL_REASONS.REQUIRED}`);
    expect(reasons(ruleSet([rule({ id: 'a', scope: 'x' as never })]))).toContain(`$.rules[0].scope|${DSL_REASONS.NOT_OBJECT}`);
    expect(reasons(ruleSet([rule({ id: 'a', scope: { productTypes: [] } })]))).toEqual([`$.rules[0].scope.productTypes|${DSL_REASONS.INVALID_TYPE}`]);
    expect(reasons(ruleSet([rule({ id: 'a', scope: {} as never })]))).toEqual([`$.rules[0].scope.productTypes|${DSL_REASONS.REQUIRED}`]);
    expect(reasons(ruleSet([rule({ id: 'a', scope: { productTypes: ['x'], rinseOff: 'no' as never } })]))).toEqual([`$.rules[0].scope.rinseOff|${DSL_REASONS.INVALID_TYPE}`]);
    expect(reasons(ruleSet([rule({ id: 'a', scope: { productTypes: ['x'], ifraCategory: '' } })]))).toEqual([`$.rules[0].scope.ifraCategory|${DSL_REASONS.INVALID_TYPE}`]);
  });

  it('checks groups, label warnings and duplicate ids', () => {
    expect(validateRuleSet(ruleSet([rule({ id: 'a', group: 'g1' })])).ok).toBe(true);
    expect(reasons(ruleSet([rule({ id: 'a', group: 'g1', operator: '>=' })]))).toEqual([`$.rules[0].group|${DSL_REASONS.GROUP_INVALID}`]);
    expect(reasons(ruleSet([rule({ id: 'a', group: 'g1', limit: '0.0' })]))).toEqual([`$.rules[0].group|${DSL_REASONS.GROUP_INVALID}`]);
    expect(reasons(ruleSet([rule({ id: 'a', group: 'g1', limit: '-1' })]))).toContain(`$.rules[0].group|${DSL_REASONS.GROUP_INVALID}`);
    expect(reasons(ruleSet([rule({ id: 'a', group: 'g1', target: { field: 'x' }, unit: '%w/w' })]))).toEqual([`$.rules[0].group|${DSL_REASONS.GROUP_INVALID}`]);
    expect(reasons(ruleSet([rule({ id: 'a', labelWarningKey: 'k', target: { function: 'f' } })]))).toEqual([`$.rules[0].labelWarningKey|${DSL_REASONS.LABEL_WARNING_TARGET}`]);
    expect(reasons(ruleSet([rule({ id: 'a' }), rule({ id: 'a' })]))).toEqual([`$.rules[1].id|${DSL_REASONS.DUPLICATE_RULE_ID}`]);
  });
});

describe('hashRuleSet', () => {
  it('is a 64-char hex, independent of key order, and changes with any content', () => {
    const a = ruleSet([rule({ id: 'a' })]);
    const reordered = JSON.parse(
      '{"rules":[{"unit":"%w/w","messageKey":"test.msg","limit":"1","operator":"<=","target":{"substanceCas":"TEST-0001"},"scope":{"productTypes":["test_product"]},"version":"1","id":"a","source":{"ref":"test ref 1","title":"Fictional source","sourceId":"TEST-SRC"}}],"status":"published","version":"1","id":"test-set"}',
    ) as typeof a;
    expect(hashRuleSet(a)).toMatch(/^[0-9a-f]{64}$/);
    expect(hashRuleSet(reordered)).toBe(hashRuleSet(a));
    expect(hashRuleSet(ruleSet([rule({ id: 'a', limit: '1.0' })]))).not.toBe(hashRuleSet(a));
    expect(hashRuleSet(ruleSet([rule({ id: 'a' })], { version: '2' }))).not.toBe(hashRuleSet(a));
  });
});
