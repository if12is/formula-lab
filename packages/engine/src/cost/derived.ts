import { dec, isDecimalString } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { yieldFraction } from '../batch/wastage';
import { decOf, inputLeaf, traced, type Traced } from '../trace/trace';

/** Cost per NET kg once wastage is covered: C_kg / (1 − w/100). EGP per kg. */
export function costPerNetKg(materialPerKg: Traced, wastagePct: string): Result<Traced> {
  const y = yieldFraction(wastagePct);
  if (!y.ok) {
    return y;
  }
  const factor = inputLeaf('Yield fraction = 1 − wastage/100', y.value, '%w/w');
  return ok(traced('div', 'Cost per net kg = C_kg / (1 − w/100)', decOf(materialPerKg).div(y.value), 'EGP', [materialPerKg, factor]));
}

/** Cost per litre = cost per kg × measured density (kg/L). Density is a traced kg/L-equivalent leaf. */
export function costPerLitre(perKg: Traced, density: Traced): Traced {
  return traced('mul', 'Cost per L = cost per kg × ρ', decOf(perKg).mul(decOf(density)), 'EGP', [perKg, density]);
}

/** Cost of one pack = pack content mass (kg) × cost per kg + packaging cost per pack. */
export function costPerPack(perKg: Traced, packMassKg: Traced, packaging: Traced): Traced {
  const content = traced('mul', 'Pack content cost = pack kg × cost per kg', decOf(packMassKg).mul(decOf(perKg)), 'EGP', [packMassKg, perKg]);
  return traced('add', 'Cost per pack = content + packaging', decOf(content).plus(decOf(packaging)), 'EGP', [content, packaging]);
}

/** C_batch = C_kg × M_gross + N × C_pack (plan/00-research/04-deterministic-formulas.md). */
export function costOfBatch(materialPerKg: Traced, grossMassKg: Traced, packagingTotal: Traced): { materials: Traced; batch: Traced } {
  const materials = traced('mul', 'Material cost of batch = C_kg × M_gross', decOf(materialPerKg).mul(decOf(grossMassKg)), 'EGP', [materialPerKg, grossMassKg]);
  const batch = traced('add', 'Batch cost = materials + N × C_pack', decOf(materials).plus(decOf(packagingTotal)), 'EGP', [materials, packagingTotal]);
  return { materials, batch };
}

/** N × C_pack. */
export function packagingOfBatch(fullPacks: Traced, packaging: Traced): Traced {
  return traced('mul', 'Packaging cost = N × C_pack', decOf(fullPacks).mul(decOf(packaging)), 'EGP', [fullPacks, packaging]);
}

/** Price = C_unit / (1 − margin/100). Margin must satisfy 0 <= margin < 100, otherwise missing. */
export function priceByMargin(unitCost: Traced, marginPct: string): Result<Traced> {
  if (!isDecimalString(marginPct)) {
    return missing([{ property: 'marginPct', reason: 'cost.margin_invalid' }]);
  }
  const margin = dec(marginPct);
  if (margin.isNegative()) {
    return missing([{ property: 'marginPct', reason: 'cost.margin_must_be_non_negative' }]);
  }
  if (margin.gte(100)) {
    return missing([{ property: 'marginPct', reason: 'cost.margin_must_be_below_100' }]);
  }
  const factor = inputLeaf('Price factor = 1 − margin/100', dec('1').minus(margin.div(100)), '%w/w');
  return ok(traced('div', 'Price = C_unit / (1 − margin/100)', decOf(unitCost).div(factor.value), 'EGP', [unitCost, factor]));
}
