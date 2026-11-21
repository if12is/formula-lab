import { describe, expect, it } from 'vitest';
import { optimize } from './optimize';
import { case2, mat, profile, seededShuffle } from './test-fixtures';

describe('J-05 deterministic tie-breaking', () => {
  // Water is free, so A (80 EGP/kg, 40 %) and B (160 EGP/kg, 80 %) cost exactly the same per active point:
  // every split of the needed 16 active points between A and B costs 32 EGP/kg. The optimal face is a whole segment.
  const tied = (idA: string, idB: string) => ({
    profile: profile({ totalActiveMinPct: '16' }),
    materials: [mat(idA, '80', '40'), mat(idB, '160', '80'), mat('WATER', '0', '0')],
    qsMaterialId: 'WATER',
  });

  it('picks the lexicographic minimum: the alphabetically first material is used least', async () => {
    const out = await optimize(tied('A_TWIN', 'B_TWIN'));
    // A_TWIN minimised first -> 0; B_TWIN = 16/0.8 = 20; water 80; cost 20·160/100 = 32
    expect(out.formula.map((l) => [l.materialId, l.percentWW])).toEqual([['WATER', '80.000'], ['B_TWIN', '20.000']]);
    expect(out.costPerKg).toBe('32');
  });

  it('the choice follows the id order, not the cost structure', async () => {
    const out = await optimize(tied('B_TWIN', 'A_TWIN')); // now A_TWIN is the 160 EGP/kg, 80 % material
    // A_TWIN (alphabetically first) minimised -> 0; B_TWIN (80 EGP/kg, 40 %) = 16/0.4 = 40; water 60; cost 40·80/100 = 32
    expect(out.formula.map((l) => [l.materialId, l.percentWW])).toEqual([['WATER', '60.000'], ['B_TWIN', '40.000']]);
    expect(out.costPerKg).toBe('32');
  });

  it('permuting the input material order gives the identical solution and hashes', async () => {
    const base = tied('A_TWIN', 'B_TWIN');
    const ref = await optimize(base);
    for (let seed = 1; seed <= 12; seed += 1) {
      const out = await optimize({ ...base, materials: seededShuffle(base.materials, seed) });
      expect(out.formula).toEqual(ref.formula);
      expect(out.inputHash).toBe(ref.inputHash);
      expect(out.outputHash).toBe(ref.outputHash);
    }
  });

  it('shuffled inputs and repeated runs give one hash on a non-degenerate case too', async () => {
    const hashes = new Set<string>();
    for (let seed = 0; seed < 10; seed += 1) {
      const input = case2();
      const out = await optimize(seed === 0 ? input : { ...input, materials: seededShuffle(input.materials, seed) });
      hashes.add(out.outputHash);
    }
    hashes.add((await optimize(case2())).outputHash);
    expect(hashes.size).toBe(1);
  });

  it('property order inside input objects does not change the hash', async () => {
    const a = await optimize(case2());
    const reordered = case2();
    reordered.materials = reordered.materials.map((m) => Object.fromEntries(Object.entries(m).reverse()) as typeof m);
    expect((await optimize(reordered)).outputHash).toBe(a.outputHash);
  });
});
