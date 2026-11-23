/**
 * T-01 convention guard. A calculation or product cannot go live without a golden case:
 *   - every calculation the product-family registry marks `available` must have a `golden[<id>]` describe in tests/golden;
 *   - every product family's available required calculations are therefore covered through that rule;
 *   - a `golden[...]` marker must name a registry calculation or one of the engine-level families listed below (typo guard).
 * Adding a product family or calculator without its golden case fails here. See README.md in this folder.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import registryJson from '../../data/product-families.json';
import { validateProductFamilyRegistry } from '../../src/families/registry';

const dir = dirname(fileURLToPath(import.meta.url));

/** Engine-level families that are not registry calculations (cost, pricing, counting, display rounding, production). */
const ENGINE_LEVEL = ['cost', 'price_waterfall', 'price_ladder', 'count_units', 'core_alcohol', 'weighing_sheet', 'deviation'];

function markers(): Set<string> {
  const found = new Set<string>();
  for (const file of readdirSync(dir).filter((f) => f.endsWith('.golden.test.ts') && f !== 'coverage.golden.test.ts')) {
    for (const m of readFileSync(join(dir, file), 'utf8').matchAll(/describe\('golden\[([a-z_]+)\]/g)) {
      found.add(m[1] as string);
    }
  }
  return found;
}

const registry = validateProductFamilyRegistry(registryJson);

describe('golden coverage convention (T-01)', () => {
  it('the registry validates (the guard reads it)', () => {
    expect(registry.ok).toBe(true);
  });

  it('every available calculation of the registry has a golden case', () => {
    if (!registry.ok) {
      throw new Error('registry invalid');
    }
    const available = registry.value.vocabulary.calculations.filter((c) => c.status === 'available').map((c) => c.id);
    const have = markers();
    expect(available.filter((id) => !have.has(id))).toEqual([]);
  });

  it('every available calculation a product family requires is golden-covered (so a new family cannot bring an uncovered calculator)', () => {
    if (!registry.ok) {
      throw new Error('registry invalid');
    }
    const available = new Set(registry.value.vocabulary.calculations.filter((c) => c.status === 'available').map((c) => c.id));
    const have = markers();
    const uncovered = registry.value.families.flatMap((f) => f.requiredCalculations.filter((id) => available.has(id) && !have.has(id)).map((id) => `${f.code}:${id}`));
    expect(uncovered).toEqual([]);
  });

  it('every golden marker names a registry calculation or an engine-level family (no typos)', () => {
    if (!registry.ok) {
      throw new Error('registry invalid');
    }
    const known = new Set([...registry.value.vocabulary.calculations.map((c) => c.id), ...ENGINE_LEVEL]);
    expect([...markers()].filter((id) => !known.has(id))).toEqual([]);
  });

  it('every engine-level family listed here is actually present', () => {
    const have = markers();
    expect(ENGINE_LEVEL.filter((id) => !have.has(id))).toEqual([]);
  });
});
