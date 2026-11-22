/**
 * The price of every line of a customer price list, by the engine's own priceListLine (rule or override against the unit cost).
 * The Laravel app calls this from App\Support\Engine\EngineRunner when a list is previewed server-side or published to Sanad,
 * so the price Sanad receives is the engine's and PHP never works one out.
 *
 * stdin : JSON `{ "lines": [{ "key": "<line id>", "unitCost": SourcedValue?, "spec": { mode: "rule", rule } | { mode: "override", price } }] }`
 * stdout: JSON `{ "lines": [{ "key", "ok": true, "mode", "price", "marginAmount", "marginOnPricePct", "markupOnCostPct", "belowCost" }
 *                           | { "key", "ok": false, "missing": [{ property, reason }] }] }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/price-list-lines.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or lacks `lines`.
 */
import { priceListLine, type PriceListLineInput } from '../src/pricing/price-list';

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
  console.error('price-list-lines: stdin is not valid JSON.');
  process.exit(2);
}

if (typeof input !== 'object' || input === null || !Array.isArray((input as { lines?: unknown }).lines)) {
  console.error('price-list-lines: expected {"lines": [...]} on stdin.');
  process.exit(2);
}

const lines = (input as { lines: Array<Pick<PriceListLineInput, 'unitCost' | 'spec'> & { key: string }> }).lines;

const answers = lines.map(({ key, unitCost, spec }) => {
  const result = priceListLine({ unitCost, spec, policy: {} });
  if (!result.ok) {
    return { key, ok: false, missing: result.missing };
  }
  const v = result.value;
  return {
    key,
    ok: true,
    mode: v.mode,
    price: v.publishedPrice,
    marginAmount: v.marginAmount.value,
    marginOnPricePct: v.marginOnPricePct,
    markupOnCostPct: v.markupOnCostPct,
    belowCost: v.belowCost,
  };
});

process.stdout.write(JSON.stringify({ lines: answers }));
