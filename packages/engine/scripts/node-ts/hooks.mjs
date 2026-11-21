/**
 * Module resolution hook for running the engine's TypeScript straight from Node (no build step).
 * The engine is written for bundlers: relative imports have no extension ("../core/decimal"). Node's ESM loader
 * wants "../core/decimal.ts", so this hook tries "<path>.ts" and "<path>/index.ts" for an extensionless relative import.
 * Loaded through ./register.mjs (see App\Support\Engine\EngineRunner).
 */
import { existsSync, statSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const isFile = (path) => existsSync(path) && statSync(path).isFile();

export async function resolve(specifier, context, nextResolve) {
  const relative = specifier.startsWith('./') || specifier.startsWith('../');

  if (relative && context.parentURL?.startsWith('file:') && !/\.[a-z]+$/i.test(specifier)) {
    const base = fileURLToPath(new URL(specifier, context.parentURL));

    for (const candidate of [`${base}.ts`, `${base}/index.ts`]) {
      if (isFile(candidate)) {
        return nextResolve(pathToFileURL(candidate).href, context);
      }
    }
  }

  return nextResolve(specifier, context);
}

/** The engine imports its data tables as bundlers allow (`import table from './x.json'`); Node wants the attribute. */
export async function load(url, context, nextLoad) {
  if (url.endsWith('.json') && context.importAttributes?.type === undefined) {
    return nextLoad(url, { ...context, importAttributes: { ...context.importAttributes, type: 'json' } });
  }

  return nextLoad(url, context);
}
