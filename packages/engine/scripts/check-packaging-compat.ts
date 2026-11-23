/**
 * The packaging compatibility check (H-02) by the engine's own checkPackagingCompatibility: may these closures and pumps go on this
 * container, given the pairs people declared?
 * The Laravel app calls this from App\Support\Engine\EngineRunner when a packaging BOM is checked.
 *
 * stdin : JSON `{ "items": [{ itemId, kind, neckFinish? }], "pairs": [{ containerNeck, closureNeck, sourceId, confidence, validFrom? }], "policy"?: { allowL7? } }`
 * stdout: JSON `{ "status": "PASS"|"FAIL"|"UNKNOWN", "checks": [...], "missing": [...], "warnings": [...] }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/check-packaging-compat.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or lacks `items` and `pairs`.
 */
import { checkPackagingCompatibility } from '../src/packaging-check/compat';

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
  console.error('check-packaging-compat: stdin is not valid JSON.');
  process.exit(2);
}

const given = input as { items?: unknown; pairs?: unknown; policy?: unknown };
if (typeof input !== 'object' || input === null || !Array.isArray(given.items) || !Array.isArray(given.pairs)) {
  console.error('check-packaging-compat: expected {"items": [...], "pairs": [...]} on stdin.');
  process.exit(2);
}

const result = checkPackagingCompatibility(
  given.items as Parameters<typeof checkPackagingCompatibility>[0],
  given.pairs as Parameters<typeof checkPackagingCompatibility>[1],
  (given.policy ?? {}) as Parameters<typeof checkPackagingCompatibility>[2],
);
process.stdout.write(JSON.stringify(result));
