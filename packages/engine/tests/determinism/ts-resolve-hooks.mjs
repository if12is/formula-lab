/**
 * Node module-customization hooks (registered by register.mjs) that let a fresh `node` process import the engine's TypeScript
 * sources the way the bundler does: extensionless relative specifiers resolve to `.ts` / `/index.ts`, and a `.json` import
 * becomes a default export. Types are stripped by Node itself (the engine uses no enums or parameter properties).
 * Test-only helper: it exists so T-04 can run the engine in a separate OS process without adding a build step or a dependency.
 */
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const TRIES = ['.ts', '/index.ts'];

export async function resolve(specifier, context, nextResolve) {
  try {
    return await nextResolve(specifier, context);
  } catch (error) {
    const relative = specifier.startsWith('.') || specifier.startsWith('/');
    if (!relative || !['ERR_MODULE_NOT_FOUND', 'ERR_UNSUPPORTED_DIR_IMPORT'].includes(error?.code)) {
      throw error;
    }
    for (const suffix of TRIES) {
      try {
        return await nextResolve(specifier.replace(/\/$/, '') + suffix, context);
      } catch {
        // try the next suffix
      }
    }
    throw error;
  }
}

export async function load(url, context, nextLoad) {
  if (url.endsWith('.json')) {
    const text = await readFile(fileURLToPath(url), 'utf8');
    return { format: 'module', source: `export default ${text};`, shortCircuit: true };
  }
  return nextLoad(url, context);
}
