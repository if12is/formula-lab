/**
 * The price ladder of a product (S-13), by the engine's own waterfall: cost -> shelf, shelf -> maximum cost, and what a move of the
 * dollar does to both. The Laravel app calls this from App\Support\Engine\EngineRunner and only shapes the answer for the screen.
 *
 * stdin : JSON `{ "forward"?: LadderForwardInput, "reverse"?: LadderReverseInput,
 *                 "fx"?: { "costReport": CostReportInput, "fillKg": "...", "changesPct": ["10", "-10"], "usdRate"?: "50" } }`
 *         (the `fx` part walks the `forward` ladder again with the unit cost moved by the USD-priced part of the cost report)
 * stdout: JSON `{ "forward"?: Result, "reverse"?: Result, "fx"?: { "exposure": Result<UsdLinkedCost>, "sensitivity": Result<FxSensitivity> | null } }`
 *         where a Result is `{ ok: true, value, warnings }` or `{ ok: false, missing, warnings }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/price-ladder.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON.
 */
import { buildCostReport, type CostReportInput } from '../src/costing/cost-report';
import { fxSensitivity, ladderForward, ladderReverse, usdLinkedCost, type LadderForwardInput, type LadderReverseInput } from '../src/pricing/ladder';

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

interface Input {
  forward?: LadderForwardInput;
  reverse?: LadderReverseInput;
  fx?: { costReport?: CostReportInput; fillKg?: string; changesPct?: string[]; usdRate?: string };
}

let input: Input;
try {
  input = JSON.parse(await readStdin()) as Input;
} catch {
  console.error('price-ladder: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof input !== 'object' || input === null) {
  console.error('price-ladder: expected a JSON object on stdin.');
  process.exit(2);
}

const out: Record<string, unknown> = {};
if (input.forward !== undefined) {
  out.forward = ladderForward(input.forward);
}
if (input.reverse !== undefined) {
  out.reverse = ladderReverse(input.reverse);
}
if (input.fx !== undefined) {
  const report = input.fx.costReport === undefined ? undefined : buildCostReport(input.fx.costReport);
  const exposure = report === undefined ? undefined : usdLinkedCost(report, input.fx.costReport?.quotes ?? {});
  out.fx = {
    exposure: exposure ?? null,
    sensitivity: input.forward === undefined
      ? null
      : fxSensitivity({ forward: input.forward, usdLinkedPerKg: exposure?.ok === true ? exposure.value.usdLinkedPerKg : undefined, fillKg: input.fx.fillKg, changesPct: input.fx.changesPct ?? [], usdRate: input.fx.usdRate }),
  };
}
process.stdout.write(JSON.stringify(out));
