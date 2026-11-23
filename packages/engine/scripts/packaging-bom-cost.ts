/**
 * The packaging cost per selling unit of one SKU's BOM (S-12), by the engine's own packagingCostPerUnit (H-03) with the declared closure
 * pairs of H-02. The Laravel app calls this from App\Support\Engine\EngineRunner for the BOM editor's cost preview; PHP and the browser
 * never add, divide or round a cost.
 *
 * stdin : JSON `{ "items": [PackagingBomItem], "packing"?: { unitsPerCarton?, cartonsPerPallet? }, "pairs": [CompatPair], "fxRates"?: [FxRate], "policy"?: ConfidencePolicy }`
 * stdout: JSON Result: `{ ok: true, value: { lines, total }, warnings }` or `{ ok: false, missing, warnings }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/packaging-bom-cost.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or lacks `items` and `pairs`.
 */
import { packagingCostPerUnit, type PackagingBomInput } from '../src/packaging/bom';

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
  console.error('packaging-bom-cost: stdin is not valid JSON.');
  process.exit(2);
}

const given = input as { items?: unknown; pairs?: unknown; packing?: unknown; fxRates?: unknown; policy?: unknown };
if (typeof input !== 'object' || input === null || !Array.isArray(given.items) || !Array.isArray(given.pairs)) {
  console.error('packaging-bom-cost: expected {"items": [...], "pairs": [...]} on stdin.');
  process.exit(2);
}

const bom: PackagingBomInput = {
  items: given.items as PackagingBomInput['items'],
  packing: given.packing as PackagingBomInput['packing'],
  fxRates: given.fxRates as PackagingBomInput['fxRates'],
  policy: (given.policy ?? {}) as PackagingBomInput['policy'],
  compatibility: { pairs: given.pairs as NonNullable<PackagingBomInput['compatibility']>['pairs'] },
};
process.stdout.write(JSON.stringify(packagingCostPerUnit(bom)));
