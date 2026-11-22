/**
 * The publish-to-Sanad decision (Y-05), by the engine's own evaluateDecision over the owner-approved rule set
 * data/decisions/publish-to-sanad.json, with its hashed decision record (Y-06). The app only fills the context (what is approved,
 * what is missing, the two sets of material prices, the configured threshold) and shows the answer; the rules and the percent
 * change are the engine's. The timestamp comes from the caller (the engine never reads a clock).
 *
 * stdin : JSON `{ "subjectRef", "actor", "at", "cost": { approved, approvedUnitCost? }, "priceWaterfall": { missingProperties: [] },
 *                 "repricing": { approved?: { "<materialId>": "<EGP/kg>" }, current?: {...}, thresholdPct? } }`
 * stdout: JSON `{ ok: true, decision, failed, passed, ruleSet: { id, version, hash }, maxChangePct, changesPct, record }`
 *         or `{ ok: false, missing }` when the decision could not be judged (a price that cannot be compared, a bad input)
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/publish-decision.ts
 */
import { readFileSync } from 'node:fs';
import { buildDecisionRecord } from '../src/decision/record';
import { countString } from '../src/decision/context';
import type { PublishToSanadContext } from '../src/decision/context';
import { hashDecisionRuleSet, validateDecisionRuleSet } from '../src/decision/dsl';
import { evaluateDecision } from '../src/decision/evaluate';
import { maxPriceChange } from '../src/decision/repricing';

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

interface Input {
  subjectRef: string;
  actor: string;
  at: string;
  cost: { approved: boolean; approvedUnitCost?: string };
  priceWaterfall: { missingProperties: string[] };
  repricing: { approved?: Record<string, string>; current?: Record<string, string>; thresholdPct?: string };
}

let input: Input;
try {
  input = JSON.parse(await readStdin()) as Input;
} catch {
  console.error('publish-decision: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof input !== 'object' || input === null || typeof input.cost !== 'object' || input.cost === null) {
  console.error('publish-decision: expected {"cost": {...}, "priceWaterfall": {...}, "repricing": {...}} on stdin.');
  process.exit(2);
}

function fail(missing: unknown): never {
  process.stdout.write(JSON.stringify({ ok: false, missing }));
  process.exit(0);
}

const rules = validateDecisionRuleSet(JSON.parse(readFileSync(new URL('../data/decisions/publish-to-sanad.json', import.meta.url), 'utf8')));
if (!rules.ok) {
  fail(rules.missing);
}
const ruleSet = rules.value;

// Without an approved cost there are no approved prices to compare with: the cost rule blocks first, the change is 0.
const approvedPrices = input.repricing?.approved ?? {};
const change = maxPriceChange(approvedPrices, input.repricing?.current ?? {}, input.repricing?.thresholdPct);
if (!change.ok) {
  fail(change.missing);
}
const priceChange = change.value;

const missingProperties = [...(input.priceWaterfall?.missingProperties ?? [])].sort();
const context: PublishToSanadContext = {
  cost: { approved: input.cost.approved, ...(input.cost.approvedUnitCost === undefined ? {} : { approvedUnitCost: input.cost.approvedUnitCost }) },
  priceWaterfall: { missingCount: countString(missingProperties), missingProperties },
  repricing: {
    maxMaterialPriceChangePct: priceChange.maxChangePct,
    changedMaterialIds: priceChange.changedMaterialIds,
    ...(input.repricing?.thresholdPct === undefined ? {} : { thresholdPct: input.repricing.thresholdPct }),
  },
};

const evaluated = evaluateDecision(ruleSet, context);
if (!evaluated.ok) {
  fail(evaluated.missing);
}
const result = evaluated.value;
const record = buildDecisionRecord({ kind: 'publish-to-sanad', subjectRef: input.subjectRef, ruleSet, context, result, actor: input.actor, at: input.at });
if (!record.ok) {
  fail(record.missing);
}

process.stdout.write(
  JSON.stringify({
    ok: true,
    decision: result.decision,
    failed: result.failed,
    passed: result.passed,
    ruleSet: { id: ruleSet.id, version: ruleSet.version, hash: hashDecisionRuleSet(ruleSet) },
    maxChangePct: priceChange.maxChangePct,
    changesPct: priceChange.changesPct,
    record: record.value,
  }),
);
