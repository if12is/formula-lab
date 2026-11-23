/**
 * The production calculations (phase Q) by the engine's own functions: the weighing sheet (Q-02), a planned-versus-actual
 * deviation (Q-11), the waste comparison (Q-04), the alkali recomputed with lot COA values (Q-03) and the actual batch cost (Q-09).
 * The Laravel app calls this from App\Support\Engine\EngineRunner and shows the answer; it calculates nothing itself.
 *
 * stdin : JSON `{ "op": "weighing-sheet" | "deviation" | "waste" | "lot-alkali" | "actual-cost" | "check-limits" | "contract-decimals" | "lot-unit-cost" | "deviations", "input": { ... } }`
 *         input is the argument object of weighingSheet / evaluateDeviation / compareWaste / recomputeAlkaliWithLots (+ `thresholdPct`) /
 *         actualBatchCost, with every number a decimal string.
 * stdout: JSON `{ ok: true, value, warnings }` or `{ ok: false, missing, warnings }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/production.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON, or the op is unknown.
 */
import { actualBatchCost, invoiceCostPerBaseUnit } from '../src/production/actual-cost';
import { contractDecimal } from '../src/production/contract-decimal';
import { checkLimits, compareWaste, evaluateDeviation } from '../src/production/deviation';
import { recomputeAlkaliWithLots } from '../src/production/lot-alkali';
import { weighingSheet } from '../src/production/weighing';

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

let request: { op?: unknown; input?: unknown };
try {
  request = JSON.parse(await readStdin()) as { op?: unknown; input?: unknown };
} catch {
  console.error('production: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof request !== 'object' || request === null || typeof request.input !== 'object' || request.input === null) {
  console.error('production: expected {"op": "...", "input": {...}} on stdin.');
  process.exit(2);
}

const input = request.input as Record<string, unknown>;
type Answer = { ok: true; value: unknown; warnings: string[] } | { ok: false; missing: unknown; warnings: string[] } | ReturnType<typeof weighingSheet> | ReturnType<typeof evaluateDeviation> | ReturnType<typeof compareWaste> | ReturnType<typeof recomputeAlkaliWithLots> | ReturnType<typeof actualBatchCost>;

let answer: Answer;
switch (request.op) {
  case 'weighing-sheet':
    answer = weighingSheet(input as unknown as Parameters<typeof weighingSheet>[0]);
    break;
  case 'deviation':
    answer = evaluateDeviation(input as unknown as Parameters<typeof evaluateDeviation>[0]);
    break;
  case 'waste':
    answer = compareWaste(input as unknown as Parameters<typeof compareWaste>[0]);
    break;
  case 'lot-alkali': {
    const { thresholdPct, ...rest } = input as { thresholdPct?: string | null };
    answer = recomputeAlkaliWithLots(rest as unknown as Parameters<typeof recomputeAlkaliWithLots>[0], thresholdPct);
    break;
  }
  case 'actual-cost':
    answer = actualBatchCost(input as unknown as Parameters<typeof actualBatchCost>[0]);
    break;
  case 'lot-unit-cost':
    answer = invoiceCostPerBaseUnit(input as unknown as Parameters<typeof invoiceCostPerBaseUnit>[0]);
    break;
  case 'deviations': {
    // { thresholdPct?, items: { key: { planned, actual, unit } } } -> { key: { ok, value | missing } }, so a batch is judged in one call.
    const items = (input.items ?? {}) as Record<string, { planned: string; actual: string; unit: Parameters<typeof evaluateDeviation>[0]['unit'] }>;
    const threshold = input.thresholdPct as string | null | undefined;
    const judged = Object.entries(items).map(([key, item]) => {
      const r = evaluateDeviation({ ...item, thresholdPct: threshold, property: key });
      return [key, r.ok ? { ok: true as const, value: r.value } : { ok: false as const, missing: r.missing }] as const;
    });
    answer = { ok: true as const, value: Object.fromEntries(judged), warnings: [] };
    break;
  }
  case 'check-limits':
    answer = checkLimits(input as unknown as Parameters<typeof checkLimits>[0]);
    break;
  case 'contract-decimals': {
    // { values: { name: decimal } } -> { name: 6-decimal string }; one missing value is a missing answer for all.
    const entries = Object.entries((input.values ?? {}) as Record<string, unknown>).map(([name, value]) => [name, contractDecimal(value, name)] as const);
    const gaps = entries.flatMap(([, r]) => (r.ok ? [] : r.missing));
    answer = gaps.length > 0 ? { ok: false as const, missing: gaps, warnings: [] } : { ok: true as const, value: Object.fromEntries(entries.map(([name, r]) => [name, r.ok ? r.value : ''])), warnings: [] };
    break;
  }
  default:
    console.error(`production: unknown op ${JSON.stringify(request.op)}.`);
    process.exit(2);
}

process.stdout.write(JSON.stringify(answer.ok ? { ok: true, value: answer.value, warnings: answer.warnings } : { ok: false, missing: answer.missing, warnings: answer.warnings }));
