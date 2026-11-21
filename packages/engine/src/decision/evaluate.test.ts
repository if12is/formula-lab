import { describe, expect, it } from 'vitest';
import { validateDecisionRuleSet, type DecisionRuleSet } from './dsl';
import { DECISION_REASONS, evaluateDecision, type DecisionContext, type DecisionResult } from './evaluate';

type Rule = Record<string, unknown>;
function set(...rules: Rule[]): DecisionRuleSet {
  const raw = {
    id: 't',
    version: '1',
    status: 'draft',
    rules: rules.map((r, i) => ({ id: `r${i + 1}`, severity: 'block', messageKey: `m${i + 1}`, remedyKey: `k${i + 1}`, ...r })),
  };
  const v = validateDecisionRuleSet(raw);
  if (!v.ok) {
    throw new Error(JSON.stringify(v.missing));
  }
  return v.value;
}

function run(rs: DecisionRuleSet, ctx: DecisionContext): DecisionResult {
  const r = evaluateDecision(rs, ctx);
  if (!r.ok) {
    throw new Error(JSON.stringify(r.missing));
  }
  return r.value;
}

const one = (rule: Rule, ctx: DecisionContext) => run(set(rule), ctx);

describe('leaf operators', () => {
  it('eq / ne on strings, booleans and decimals', () => {
    expect(one({ operator: 'eq', field: 'a', value: 'x' }, { a: 'x' }).decision).toBe('allowed');
    expect(one({ operator: 'eq', field: 'a', value: 'x' }, { a: 'y' }).failed[0]?.reason).toBe('unmet');
    expect(one({ operator: 'eq', field: 'a', value: '100' }, { a: '100.000' }).decision).toBe('allowed');
    expect(one({ operator: 'eq', field: 'a', value: true }, { a: true }).decision).toBe('allowed');
    expect(one({ operator: 'eq', field: 'a', value: true }, { a: false }).decision).toBe('blocked');
    expect(one({ operator: 'eq', field: 'a', value: true }, { a: 'true' }).decision).toBe('blocked');
    expect(one({ operator: 'ne', field: 'a', value: 'x' }, { a: 'y' }).decision).toBe('allowed');
    expect(one({ operator: 'ne', field: 'a', value: 'x' }, { a: 'x' }).decision).toBe('blocked');
  });

  it('ordering operators use exact decimals', () => {
    const ctx = { a: '0.3' };
    expect(one({ operator: 'lte', field: 'a', value: '0.30' }, ctx).decision).toBe('allowed');
    expect(one({ operator: 'lt', field: 'a', value: '0.30' }, ctx).decision).toBe('blocked');
    expect(one({ operator: 'gte', field: 'a', value: '0.3' }, ctx).decision).toBe('allowed');
    expect(one({ operator: 'gt', field: 'a', value: '0.2999999999999999999999' }, ctx).decision).toBe('allowed');
    expect(one({ operator: 'gt', field: 'a', value: '0.3' }, ctx).decision).toBe('blocked');
  });

  it('wrong types are reported as invalid, not as a pass', () => {
    expect(one({ operator: 'lt', field: 'a', value: '5' }, { a: 'abc' }).failed[0]?.reason).toBe('invalid');
    expect(one({ operator: 'eq', field: 'a', value: '5' }, { a: ['1'] }).failed[0]?.reason).toBe('invalid');
    expect(one({ operator: 'eq', field: 'a', value: '5' }, { a: { b: '1' } }).failed[0]?.reason).toBe('invalid');
    expect(one({ operator: 'in', field: 'a', value: ['x'] }, { a: true }).failed[0]?.reason).toBe('invalid');
  });

  it('in / notIn on scalars and arrays', () => {
    expect(one({ operator: 'in', field: 'a', value: ['x', 'y'] }, { a: 'y' }).decision).toBe('allowed');
    expect(one({ operator: 'in', field: 'a', value: ['x'] }, { a: 'y' }).decision).toBe('blocked');
    expect(one({ operator: 'in', field: 'a', value: ['x', 'y'] }, { a: ['x', 'y'] }).decision).toBe('allowed');
    expect(one({ operator: 'in', field: 'a', value: ['x'] }, { a: ['x', 'z'] }).decision).toBe('blocked');
    expect(one({ operator: 'in', field: 'a', value: ['x'] }, { a: [] }).decision).toBe('allowed');
    expect(one({ operator: 'notIn', field: 'a', value: ['x'] }, { a: 'y' }).decision).toBe('allowed');
    expect(one({ operator: 'notIn', field: 'a', value: ['x'] }, { a: ['y', 'x'] }).decision).toBe('blocked');
  });

  it('exists / notExists never report missing', () => {
    expect(one({ operator: 'exists', field: 'a.b' }, { a: { b: '1' } }).decision).toBe('allowed');
    expect(one({ operator: 'exists', field: 'a.b' }, {}).failed[0]).toMatchObject({ reason: 'unmet', missingFields: [] });
    expect(one({ operator: 'exists', field: 'a.b' }, { a: { b: null } }).decision).toBe('blocked');
    expect(one({ operator: 'notExists', field: 'a' }, {}).decision).toBe('allowed');
    expect(one({ operator: 'notExists', field: 'a' }, { a: '0' }).decision).toBe('blocked');
  });

  it('a missing field fails with reason missing, for every operator', () => {
    for (const rule of [
      { operator: 'eq', value: '1' },
      { operator: 'ne', value: '1' },
      { operator: 'lt', value: '1' },
      { operator: 'in', value: ['1'] },
      { operator: 'notIn', value: ['1'] },
    ]) {
      const r = one({ ...rule, field: 'a.b' }, { a: {} });
      expect(r.decision).toBe('blocked');
      expect(r.failed[0]).toMatchObject({ reason: 'missing', missingFields: ['a.b'] });
    }
    // path through a non-object
    expect(one({ operator: 'eq', field: 'a.b', value: '1' }, { a: 'str' }).failed[0]?.reason).toBe('missing');
  });

  it('valueFrom compares two context fields; a missing side is missing', () => {
    const rule = { operator: 'lte', field: 'x', valueFrom: 'limit' };
    expect(one(rule, { x: '4', limit: '5' }).decision).toBe('allowed');
    expect(one(rule, { x: '6', limit: '5' }).failed[0]?.reason).toBe('unmet');
    expect(one(rule, { x: '4' }).failed[0]).toMatchObject({ reason: 'missing', missingFields: ['limit'] });
    expect(one(rule, {}).failed[0]).toMatchObject({ reason: 'missing', missingFields: ['limit', 'x'] });
  });
});

describe('composites', () => {
  const kids = (...c: Rule[]) => c.map((x, i) => ({ id: `c${i}`, ...x }));
  const T = { operator: 'eq', field: 'a', value: '1' };
  const F = { operator: 'eq', field: 'a', value: '2' };
  const M = { operator: 'eq', field: 'zz', value: '2' };
  const I = { operator: 'lt', field: 'a', value: '2' };
  const ctx = { a: 'abc' };
  const okCtx = { a: '1' };
  const check = (operator: string, children: Rule[], c: DecisionContext) => run(set({ operator, conditions: kids(...children) }), c);

  it('all', () => {
    expect(check('all', [T, T], okCtx).decision).toBe('allowed');
    expect(check('all', [T, F], okCtx).failed[0]?.reason).toBe('unmet');
    expect(check('all', [T, M], okCtx).failed[0]).toMatchObject({ reason: 'missing', missingFields: ['zz'] });
    expect(check('all', [I, M], ctx).failed[0]?.reason).toBe('invalid');
    expect(check('all', [F, M], okCtx).failed[0]?.reason).toBe('unmet');
  });

  it('any', () => {
    expect(check('any', [F, T], okCtx).decision).toBe('allowed');
    expect(check('any', [M, T], okCtx).decision).toBe('allowed');
    expect(check('any', [F, F], okCtx).failed[0]?.reason).toBe('unmet');
    expect(check('any', [F, M], okCtx).failed[0]?.reason).toBe('missing');
    expect(check('any', [I], ctx).failed[0]?.reason).toBe('invalid');
  });

  it('none', () => {
    expect(check('none', [F, F], okCtx).decision).toBe('allowed');
    expect(check('none', [F, T], okCtx).failed[0]?.reason).toBe('unmet');
    expect(check('none', [F, M], okCtx).failed[0]?.reason).toBe('missing');
    expect(check('none', [F, I], ctx).failed[0]?.reason).toBe('invalid');
  });

  it('nests', () => {
    const rs = set({
      operator: 'all',
      conditions: [{ id: 'n1', operator: 'any', conditions: [{ id: 'n2', operator: 'eq', field: 'a', value: '9' }, { id: 'n3', operator: 'eq', field: 'a', value: '1' }] }],
    });
    expect(run(rs, okCtx).decision).toBe('allowed');
    expect(run(rs, { a: '3' }).decision).toBe('blocked');
  });
});

describe('result shape', () => {
  const rs = set(
    { operator: 'eq', field: 'a', value: '1', params: { aValue: 'a', list: 'l', flag: 'f', nest: 'n', gone: 'nothing' } },
    { operator: 'eq', field: 'b', value: '1', severity: 'warn' },
    { operator: 'exists', field: 'c' },
  );

  it('decision levels', () => {
    expect(run(rs, { a: '1', b: '1', c: 'x' }).decision).toBe('allowed');
    expect(run(rs, { a: '1', b: '0', c: 'x' }).decision).toBe('allowed_with_warnings');
    expect(run(rs, { a: '0', b: '1', c: 'x' }).decision).toBe('blocked');
  });

  it('lists failed (with params, remedy) and passed in rule order', () => {
    const r = run(rs, { a: '2', l: ['p', 'q'], f: true, n: { x: '1' }, b: '0' });
    expect(r.passed).toEqual([]);
    expect(r.failed.map((f) => f.ruleId)).toEqual(['r1', 'r2', 'r3']);
    expect(r.failed[0]).toEqual({
      ruleId: 'r1',
      severity: 'block',
      messageKey: 'm1',
      remedyKey: 'k1',
      reason: 'unmet',
      missingFields: [],
      params: { aValue: '2', flag: 'true', list: 'p,q' },
    });
    expect(r.failed[1]?.severity).toBe('warn');
    const ok = run(rs, { a: '1', b: '1', c: 'x' });
    expect(ok.passed).toEqual(['r1', 'r2', 'r3']);
  });

  it('is deterministic regardless of context key order', () => {
    const a = run(rs, { a: '2', b: '0', l: ['p'], c: 'x' });
    const b = run(rs, { c: 'x', l: ['p'], b: '0', a: '2' });
    expect(a).toEqual(b);
    expect(a.contextHash).toBe(b.contextHash);
    expect(a.ruleSetHash).toMatch(/^[0-9a-f]{64}$/);
    expect(run(rs, { a: '3' }).contextHash).not.toBe(a.contextHash);
  });
});

describe('invalid inputs', () => {
  const rs = set({ operator: 'exists', field: 'a' });
  it('rejects an invalid rule set', () => {
    const r = evaluateDecision({ id: '' } as unknown as DecisionRuleSet, {});
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing[0]?.reason.startsWith(DECISION_REASONS.RULESET_INVALID)).toBe(true);
  });
  it('rejects a non-object, JS numbers and unsupported values in the context', () => {
    const reason = (c: unknown) => {
      const r = evaluateDecision(rs, c as DecisionContext);
      return r.ok ? 'ok' : r.missing[0]?.reason;
    };
    expect(reason('x')).toBe(DECISION_REASONS.CONTEXT_NOT_OBJECT);
    expect(reason({ a: 1 })).toBe(DECISION_REASONS.CONTEXT_JS_NUMBER);
    expect(reason({ a: [{ b: '1' }] })).toBe(DECISION_REASONS.CONTEXT_INVALID_VALUE);
    expect(reason({ a: new Date(0) })).toBe(DECISION_REASONS.CONTEXT_INVALID_VALUE);
  });
});
