/**
 * Decision record (Y-06, ALCOA+ idea): who decided, when, on which inputs and rules, with what outcome and why.
 * `decisionHash` covers only inputs + rules + evaluation result, so the same inputs always give the same
 * decisionHash. `recordHash` covers everything (actor, time, reason, override). An override never erases the
 * original failed list. The caller supplies the timestamp (the engine never reads a clock).
 */
import type { MissingData } from '../contract';
import { canonicalHash } from '../core/canonical-hash';
import { missing, ok, type Result } from '../core/result';
import { nonEmptyString } from '../core/validate';
import { evaluateDecision, type Decision, type DecisionContext, type DecisionResult, type FailedRule } from './evaluate';
import { hashDecisionRuleSet, type DecisionRuleSet } from './dsl';

export type DecisionOutcome = Decision | 'overridden';

export type DecisionOverride = {
  by: string;
  role: string;
  reason: string;
  /** Reference to the e-signature event (password re-entry) stored by the app. */
  signatureRef: string;
};

export type DecisionRecord = {
  kind: string;
  subjectRef: string;
  ruleSetId: string;
  ruleSetVersion: string;
  ruleSetHash: string;
  /** Hash of the decision context (the inputs). */
  contextHash: string;
  /** What the rules said. Equals `result` unless overridden. */
  originalDecision: Decision;
  result: DecisionOutcome;
  /** Failed rules exactly as evaluated; kept even when overridden. */
  reasons: FailedRule[];
  passed: string[];
  actor: string;
  /** ISO 8601 timestamp supplied by the caller. */
  at: string;
  reason?: string;
  override?: DecisionOverride;
  decisionHash: string;
  recordHash: string;
};

export interface BuildDecisionRecordInput {
  kind: string;
  subjectRef: string;
  ruleSet: DecisionRuleSet;
  context: DecisionContext;
  result: DecisionResult;
  actor: string;
  /** ISO 8601 timestamp, e.g. 2026-11-21T10:30:00Z. */
  at: string;
  reason?: string;
  override?: DecisionOverride;
}

export const RECORD_REASONS = {
  REQUIRED: 'decision.record.required',
  INVALID_TIMESTAMP: 'decision.record.invalid_timestamp',
  RESULT_MISMATCH: 'decision.record.result_mismatch',
  OVERRIDE_NOT_BLOCKED: 'decision.override.only_for_blocked',
  OVERRIDE_INCOMPLETE: 'decision.override.incomplete',
} as const;

const ISO_TIMESTAMP = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])T([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d+)?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/;

export function isIsoTimestamp(x: unknown): x is string {
  return typeof x === 'string' && ISO_TIMESTAMP.test(x);
}

/** Hash over inputs + rules + result only (no actor, no time). */
export function computeDecisionHash(result: DecisionResult): string {
  return canonicalHash({
    contextHash: result.contextHash,
    ruleSetHash: result.ruleSetHash,
    decision: result.decision,
    failed: result.failed,
    passed: result.passed,
  });
}

export function buildDecisionRecord(input: BuildDecisionRecordInput): Result<DecisionRecord> {
  const issues: MissingData[] = [];
  for (const key of ['kind', 'subjectRef', 'actor'] as const) {
    if (!nonEmptyString(input[key])) {
      issues.push({ property: key, reason: RECORD_REASONS.REQUIRED });
    }
  }
  if (!isIsoTimestamp(input.at)) {
    issues.push({ property: 'at', reason: RECORD_REASONS.INVALID_TIMESTAMP });
  }
  const { override } = input;
  if (override !== undefined) {
    for (const key of ['by', 'role', 'reason', 'signatureRef'] as const) {
      if (!nonEmptyString(override[key])) {
        issues.push({ property: `override.${key}`, reason: RECORD_REASONS.OVERRIDE_INCOMPLETE });
      }
    }
    if (input.result.decision !== 'blocked') {
      issues.push({ property: 'override', reason: RECORD_REASONS.OVERRIDE_NOT_BLOCKED });
    }
  }
  // The result must belong to this rule set and context (no mixing a result with other inputs).
  const replayed = evaluateDecision(input.ruleSet, input.context);
  if (!replayed.ok) {
    return missing([...issues, ...replayed.missing]);
  }
  if (computeDecisionHash(replayed.value) !== computeDecisionHash(input.result) || hashDecisionRuleSet(input.ruleSet) !== input.result.ruleSetHash) {
    issues.push({ property: 'result', reason: RECORD_REASONS.RESULT_MISMATCH });
  }
  if (issues.length > 0) {
    return missing(issues);
  }
  const body: Omit<DecisionRecord, 'recordHash'> = {
    kind: input.kind,
    subjectRef: input.subjectRef,
    ruleSetId: input.ruleSet.id,
    ruleSetVersion: input.ruleSet.version,
    ruleSetHash: input.result.ruleSetHash,
    contextHash: input.result.contextHash,
    originalDecision: input.result.decision,
    result: override === undefined ? input.result.decision : 'overridden',
    reasons: input.result.failed,
    passed: input.result.passed,
    actor: input.actor,
    at: input.at,
    ...(input.reason === undefined ? {} : { reason: input.reason }),
    ...(override === undefined ? {} : { override: { by: override.by, role: override.role, reason: override.reason, signatureRef: override.signatureRef } }),
    decisionHash: computeDecisionHash(input.result),
  };
  return ok({ ...body, recordHash: canonicalHash(body) });
}

export interface RecordVerification {
  /** Re-evaluating the stored rule set + context gives the same decisionHash. */
  decisionReplays: boolean;
  /** The stored recordHash matches the record's content. */
  recordIntact: boolean;
}

/** Replay check (Y-06): re-run the rules on the stored inputs and compare the hashes. */
export function verifyDecisionRecord(record: DecisionRecord, ruleSet: DecisionRuleSet, context: DecisionContext): Result<RecordVerification> {
  const replayed = evaluateDecision(ruleSet, context);
  if (!replayed.ok) {
    return missing(replayed.missing);
  }
  const { recordHash, ...body } = record;
  return ok({
    decisionReplays: computeDecisionHash(replayed.value) === record.decisionHash && replayed.value.contextHash === record.contextHash && replayed.value.ruleSetHash === record.ruleSetHash,
    recordIntact: canonicalHash(body) === recordHash,
  });
}
