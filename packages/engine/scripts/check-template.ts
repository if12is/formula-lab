/**
 * Engine validation and compliance of a template use, by the engine's own checkTemplateUse. The Laravel app calls this from
 * App\Support\Engine\EngineRunner before a formula is created from a template, so PHP never balances a q.s. line, sums a formula or
 * judges a limit itself.
 *
 * stdin : JSON `{ "template", "fill", "materials", "ruleSets", "context" }` (the shape of the engine's TemplateUseInput)
 * stdout: JSON `{ "ok": true, "report": TemplateUseReport }` or `{ "ok": false, "missing": [{ materialId?, property, reason }] }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/check-template.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or lacks a template.
 */
import { checkTemplateUse, type TemplateUseInput } from '../src/families';

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
  console.error('check-template: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof input !== 'object' || input === null || !('template' in input) || !('fill' in input)) {
  console.error('check-template: expected {"template": ..., "fill": ...} on stdin.');
  process.exit(2);
}

const given = input as Partial<TemplateUseInput>;
const result = checkTemplateUse({
  template: given.template as TemplateUseInput['template'],
  fill: given.fill ?? {},
  materials: given.materials ?? [],
  ruleSets: given.ruleSets ?? [],
  context: given.context as TemplateUseInput['context'],
});
process.stdout.write(JSON.stringify(result.ok ? { ok: true, report: result.value } : { ok: false, missing: result.missing }));
