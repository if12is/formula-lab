/**
 * Validates the product family registry and the formula template library with the engine's own validators.
 * The Laravel app calls this from App\Support\Engine\EngineRunner before it loads the shipped data files into the database, so
 * what is stored passed the same checks the engine's tests apply to the files.
 *
 * stdin : JSON `{ "registry": {...}, "templates": {...} }`
 * stdout: JSON `{ "ok": true }` or `{ "ok": false, "missing": [{ "property", "reason" }] }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/validate-families.ts
 * Exit 0 for any answer, validation failure included; non-zero only when the input is not JSON or lacks a part.
 */
import { validateFormulaTemplates, validateProductFamilyRegistry } from '../src/families';

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

let input: { registry?: unknown; templates?: unknown };
try {
  input = JSON.parse(await readStdin());
} catch {
  console.error('validate-families: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof input !== 'object' || input === null || !('registry' in input) || !('templates' in input)) {
  console.error('validate-families: expected {"registry": ..., "templates": ...} on stdin.');
  process.exit(2);
}

const registry = validateProductFamilyRegistry(input.registry);
if (!registry.ok) {
  process.stdout.write(JSON.stringify({ ok: false, missing: registry.missing }));
} else {
  const templates = validateFormulaTemplates(input.templates, registry.value);
  process.stdout.write(JSON.stringify(templates.ok ? { ok: true } : { ok: false, missing: templates.missing }));
}
