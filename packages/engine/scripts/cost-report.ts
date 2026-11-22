/**
 * The auditable cost report of a formula version (I-08), by the engine's own calculate (the same figure as the formula editor).
 * The Laravel app calls this from App\Support\Engine\EngineRunner and only shapes the answer for the screen and the print view.
 *
 * stdin : JSON `{ "datasetVersion", "lines": [{ materialId, percentWW?, qs? }], "quotes": { "<materialId>": [Candidate] }, "fxRates"?: [FxRate] }`
 * stdout: JSON CostReport (see src/costing/cost-report.ts): `{ ok, lines, total, missing, warnings, inputHash, outputHash, ... }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/cost-report.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or lacks `lines`.
 */
import { buildCostReport, type CostReportInput } from '../src/costing/cost-report';

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
  console.error('cost-report: stdin is not valid JSON.');
  process.exit(2);
}

const body = input as Partial<CostReportInput> | null;
if (typeof body !== 'object' || body === null || !Array.isArray(body.lines) || typeof body.datasetVersion !== 'string') {
  console.error('cost-report: expected {"datasetVersion": "...", "lines": [...], "quotes": {...}} on stdin.');
  process.exit(2);
}

process.stdout.write(JSON.stringify(buildCostReport({ datasetVersion: body.datasetVersion, lines: body.lines, quotes: body.quotes ?? {}, fxRates: body.fxRates })));
