/**
 * The tier optimizer (J-01 ... J-08) as a process, for the Claude Haiku assistant's `optimize_tier` tool (P-02). The Laravel app calls it
 * from App\Support\Engine\EngineRunner (script `optimize-tier`); it adds nothing to the optimizer's own `optimize`.
 *
 * stdin : JSON OptimizerInput (`profile`, `materials`, `qsMaterialId?`, `policy?`, `reversePricing?`, `scenarios?`)
 * stdout: JSON OptimizerOutput (`ok`, `status`, `missing`, `errors`, `formula`, `costPerKg`, `binding`, ...)
 * Run   : (cwd packages/engine) node --experimental-transform-types --import ./scripts/node-ts/register.mjs ../optimizer/scripts/optimize.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON.
 */
import { optimize, type OptimizerInput } from '../src/index';

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
  console.error('optimize: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof input !== 'object' || input === null || !('profile' in input) || !Array.isArray((input as { materials?: unknown }).materials)) {
  console.error('optimize: expected {"profile": ..., "materials": [...]} on stdin.');
  process.exit(2);
}

process.stdout.write(JSON.stringify(await optimize(input as OptimizerInput)));
