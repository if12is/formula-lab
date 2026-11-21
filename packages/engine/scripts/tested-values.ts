/**
 * The doses and required HLBs a formula version used, for the "tested on formula X" records (O-07), by the engine's own
 * testedValuesOf (the q.s. line is balanced and the required HLB is the value resolveValue picks).
 * The Laravel app calls this from App\Support\Engine\EngineRunner when a stability result stands on an approved version.
 *
 * stdin : JSON `{ "lines": [{ materialId, percentWW?, qs? }], "rhlb": { "<materialId>": [{ value, sourceId, confidence, validFrom, lotNo? }] } }`
 * stdout: JSON `{ "ok": true, "values": [{ kind, materialId, value, sourceId? }] }` or `{ "ok": false, "missing": [...] }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/tested-values.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or lacks `lines`.
 */
import { testedValuesOf } from '../src/lab/tested-on';

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
  console.error('tested-values: stdin is not valid JSON.');
  process.exit(2);
}

if (typeof input !== 'object' || input === null || !Array.isArray((input as { lines?: unknown }).lines)) {
  console.error('tested-values: expected {"lines": [...], "rhlb": {...}} on stdin.');
  process.exit(2);
}

const { lines, rhlb } = input as { lines: Parameters<typeof testedValuesOf>[0]; rhlb?: Parameters<typeof testedValuesOf>[1] };
const result = testedValuesOf(lines, rhlb ?? {});
process.stdout.write(JSON.stringify(result.ok ? { ok: true, values: result.value } : { ok: false, missing: result.missing }));
