import { describe, expect, it } from 'vitest';
import { validateDecisionRuleSet, type DecisionRuleSet } from './dsl';
import { evaluateDecision, type DecisionContext, type DecisionResult } from './evaluate';
import { buildDecisionRecord, computeDecisionHash, isIsoTimestamp, RECORD_REASONS, verifyDecisionRecord, type BuildDecisionRecordInput, type DecisionRecord } from './record';

const v = validateDecisionRuleSet({
  id: 'demo',
  version: '1.0.0',
  status: 'draft',
  rules: [
    { id: 'D-1', operator: 'eq', field: 'x.total', value: '100', params: { total: 'x.total' }, severity: 'block', messageKey: 'm1', remedyKey: 'k1' },
    { id: 'D-2', operator: 'exists', field: 'x.note', severity: 'warn', messageKey: 'm2', remedyKey: 'k2' },
  ],
});
if (!v.ok) {
  throw new Error('bad fixture');
}
const ruleSet: DecisionRuleSet = v.value;
const T = '2026-11-21T10:30:00Z';

function evalOk(ctx: DecisionContext): DecisionResult {
  const r = evaluateDecision(ruleSet, ctx);
  if (!r.ok) {
    throw new Error('eval');
  }
  return r.value;
}

function input(ctx: DecisionContext, extra: Partial<BuildDecisionRecordInput> = {}): BuildDecisionRecordInput {
  return { kind: 'approve_formula_version', subjectRef: 'formula_version:fv-1', ruleSet, context: ctx, result: evalOk(ctx), actor: 'user-1', at: T, ...extra };
}

function build(i: BuildDecisionRecordInput): DecisionRecord {
  const r = buildDecisionRecord(i);
  if (!r.ok) {
    throw new Error(JSON.stringify(r.missing));
  }
  return r.value;
}

const blockedCtx = { x: { total: '90' } };
const allowedCtx = { x: { total: '100', note: 'ok' } };
const override = { by: 'user-2', role: 'quality_manager', reason: 'Approved trial batch, deviation DEV-1', signatureRef: 'esig-123' };

describe('buildDecisionRecord', () => {
  it('records hashes, result, reasons, actor and time', () => {
    const rec = build(input(allowedCtx));
    expect(rec).toMatchObject({
      kind: 'approve_formula_version',
      subjectRef: 'formula_version:fv-1',
      ruleSetId: 'demo',
      ruleSetVersion: '1.0.0',
      originalDecision: 'allowed',
      result: 'allowed',
      reasons: [],
      passed: ['D-1', 'D-2'],
      actor: 'user-1',
      at: T,
    });
    expect(rec.contextHash).toBe(evalOk(allowedCtx).contextHash);
    expect(rec.ruleSetHash).toBe(evalOk(allowedCtx).ruleSetHash);
    expect(rec.recordHash).toMatch(/^[0-9a-f]{64}$/);
    expect(rec.override).toBeUndefined();
    expect('reason' in rec).toBe(false);
  });

  it('keeps the free-text reason and is deterministic for the same input', () => {
    const a = build(input(blockedCtx, { reason: 'because' }));
    const b = build(input({ x: { total: '90' } }, { reason: 'because' }));
    expect(a.reason).toBe('because');
    expect(a).toEqual(b);
  });

  it('decisionHash ignores actor and time; recordHash does not', () => {
    const a = build(input(blockedCtx));
    const b = build(input(blockedCtx, { actor: 'user-9', at: '2027-01-01T00:00:00+02:00' }));
    expect(a.decisionHash).toBe(b.decisionHash);
    expect(a.recordHash).not.toBe(b.recordHash);
    expect(a.decisionHash).toBe(computeDecisionHash(evalOk(blockedCtx)));
  });

  it('decisionHash changes with inputs or rules', () => {
    const a = build(input(blockedCtx));
    const c = build(input({ x: { total: '95' } }));
    expect(c.decisionHash).not.toBe(a.decisionHash);
    const rs2: DecisionRuleSet = { ...ruleSet, version: '1.0.1' };
    const r2 = evaluateDecision(rs2, blockedCtx);
    expect(r2.ok && buildDecisionRecord({ ...input(blockedCtx), ruleSet: rs2, result: r2.value }).ok).toBe(true);
    expect(r2.ok && r2.value.ruleSetHash).not.toBe(a.ruleSetHash);
  });

  it('override: result becomes overridden and the original failed list is kept', () => {
    const plain = build(input(blockedCtx));
    const rec = build(input(blockedCtx, { override }));
    expect(rec.result).toBe('overridden');
    expect(rec.originalDecision).toBe('blocked');
    expect(rec.reasons).toEqual(plain.reasons);
    expect(rec.reasons.map((r) => r.ruleId)).toEqual(['D-1', 'D-2']);
    expect(rec.override).toEqual(override);
    expect(rec.decisionHash).toBe(plain.decisionHash);
    expect(rec.recordHash).not.toBe(plain.recordHash);
  });

  it('rejects an incomplete override and an override of a non-blocked decision', () => {
    const r = buildDecisionRecord(input(blockedCtx, { override: { ...override, signatureRef: ' ', role: '' } }));
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['override.role', 'override.signatureRef']);
    const r2 = buildDecisionRecord(input(allowedCtx, { override }));
    expect(!r2.ok && r2.missing[0]?.reason).toBe(RECORD_REASONS.OVERRIDE_NOT_BLOCKED);
  });

  it('rejects missing fields and bad timestamps', () => {
    const r = buildDecisionRecord(input(blockedCtx, { kind: '', subjectRef: '', actor: ' ', at: '2026-11-21' }));
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['kind', 'subjectRef', 'actor', 'at']);
  });

  it('rejects a result that does not belong to the given rule set and context', () => {
    const r = buildDecisionRecord({ ...input(blockedCtx), result: evalOk(allowedCtx) });
    expect(!r.ok && r.missing[0]?.reason).toBe(RECORD_REASONS.RESULT_MISMATCH);
    const tampered = { ...evalOk(blockedCtx), ruleSetHash: 'f'.repeat(64) };
    const r2 = buildDecisionRecord({ ...input(blockedCtx), result: tampered });
    expect(!r2.ok && r2.missing[0]?.reason).toBe(RECORD_REASONS.RESULT_MISMATCH);
  });

  it('passes on evaluation errors (invalid context)', () => {
    const r = buildDecisionRecord({ ...input(blockedCtx), context: { a: 1 } as unknown as DecisionContext });
    expect(r.ok).toBe(false);
  });
});

describe('verifyDecisionRecord (replay)', () => {
  it('replays to the same decision and detects tampering', () => {
    const rec = build(input(blockedCtx, { override }));
    const ok = verifyDecisionRecord(rec, ruleSet, blockedCtx);
    expect(ok.ok && ok.value).toEqual({ decisionReplays: true, recordIntact: true });
    const other = verifyDecisionRecord(rec, ruleSet, { x: { total: '100' } });
    expect(other.ok && other.value.decisionReplays).toBe(false);
    const edited = verifyDecisionRecord({ ...rec, actor: 'someone-else' }, ruleSet, blockedCtx);
    expect(edited.ok && edited.value).toEqual({ decisionReplays: true, recordIntact: false });
    const rs2: DecisionRuleSet = { ...ruleSet, version: '9' };
    const changed = verifyDecisionRecord(rec, rs2, blockedCtx);
    expect(changed.ok && changed.value.decisionReplays).toBe(false);
  });

  it('reports evaluation errors', () => {
    const rec = build(input(blockedCtx));
    expect(verifyDecisionRecord(rec, ruleSet, { a: 1 } as unknown as DecisionContext).ok).toBe(false);
  });
});

describe('isIsoTimestamp', () => {
  it('accepts ISO 8601 date-times with zone and rejects the rest', () => {
    expect(isIsoTimestamp('2026-11-21T10:30:00Z')).toBe(true);
    expect(isIsoTimestamp('2026-11-21T10:30:00.123+02:00')).toBe(true);
    expect(isIsoTimestamp('2026-11-21T10:30:00')).toBe(false);
    expect(isIsoTimestamp('2026-13-21T10:30:00Z')).toBe(false);
    expect(isIsoTimestamp(5)).toBe(false);
  });
});
