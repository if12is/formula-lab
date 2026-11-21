/**
 * Validates a compliance rule set with the engine's own validator and gives back its hash.
 * The Laravel app calls this from App\Support\Engine\EngineRunner when a rule set is published, so the server
 * re-validates what the browser already validated, with the same code (validateRuleSet, hashRuleSet).
 *
 * stdin : JSON `{ "ruleSet": { id, version, status, rules } }`
 * stdout: JSON `{ "ok": true, "hash": "<sha256>" }` or `{ "ok": false, "missing": [{ "property", "reason" }] }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/validate-rule-set.ts
 *         (transform-types, not strip-types: the engine uses constructor parameter properties.)
 * Exit 0 for any answer, validation failure included; non-zero only when the input is not JSON or has no ruleSet.
 */
import { hashRuleSet, validateRuleSet } from '../src/compliance/dsl';

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

let input: unknown;
try {
  input = JSON.parse(await readStdin());
} catch {
  console.error('validate-rule-set: stdin is not valid JSON.');
  process.exit(2);
}

if (typeof input !== 'object' || input === null || !('ruleSet' in input)) {
  console.error('validate-rule-set: expected {"ruleSet": ...} on stdin.');
  process.exit(2);
}

const result = validateRuleSet((input as { ruleSet: unknown }).ruleSet);
process.stdout.write(
  JSON.stringify(result.ok ? { ok: true, hash: hashRuleSet(result.value) } : { ok: false, missing: result.missing }),
);
