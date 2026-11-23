/**
 * The EDA notification file assistant (N-08) by the engine's own functions: the checklist of a product's family group and how ready the file is.
 * The Laravel app calls this from App\Support\Engine\EngineRunner and shows the answer; it decides nothing about what a file needs.
 *
 * stdin : JSON `{ "requests": [{ "familyGroup": string | null, "statuses": { "<item code>": "missing" | "attached" | "not_applicable" } }], "data": {...} (optional: another checklist file, validated) }`
 * stdout: JSON `{ "answers": [...] }`, one per request in order, each `{ ok: true, value, warnings }` or `{ ok: false, missing, warnings }`; value is `{ state, checklistId, regulator, reference, items, counts }`.
 *         A product list asks for all its rows in one run. If the registry or `data` is invalid, every answer is that failure.
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/eda-checklist.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON.
 */
import registryJson from '../data/product-families.json';
import { EDA_CHECKLIST, edaReadiness, validateEdaChecklist } from '../src/eda-checklist';
import { validateProductFamilyRegistry } from '../src/families';

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

let request: { requests?: unknown; data?: unknown };
try {
  request = JSON.parse(await readStdin()) as typeof request;
} catch {
  console.error('eda-checklist: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof request !== 'object' || request === null || !Array.isArray(request.requests)) {
  console.error('eda-checklist: expected {"requests": [...]} on stdin.');
  process.exit(2);
}

const requests = request.requests as Array<{ familyGroup?: unknown; statuses?: unknown }>;
const registry = validateProductFamilyRegistry(registryJson);
const data = !registry.ok ? registry : request.data === undefined ? { ok: true as const, value: EDA_CHECKLIST, warnings: [] } : validateEdaChecklist(request.data, registry.value);
const answers = requests.map((one) => {
  if (!data.ok) {
    return data;
  }
  const statuses = typeof one.statuses === 'object' && one.statuses !== null && !Array.isArray(one.statuses) ? (one.statuses as Record<string, string>) : {};
  return edaReadiness(data.value, { familyGroup: typeof one.familyGroup === 'string' ? one.familyGroup : null, statuses });
});
process.stdout.write(JSON.stringify({ answers }));
