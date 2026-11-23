/**
 * Actual cost of a production batch against the computed one (Q-09). Pure, decimal.js only, EGP.
 *
 *   materials  = Σ quantity_i x unitCost_i      (quantity in the unit the unit cost is per; the caller states both)
 *   packaging  = Σ quantity_j x unitCost_j
 *   conversion = the conversion cost actually spent on the batch (an input with its source)
 *   total      = materials + packaging + conversion;  per unit = total / units produced
 *
 * A cost line with no unit cost, or a missing conversion/units figure, stops the calculation and is named in missing[]:
 * a gap is never a zero. The comparison with the computed unit cost reuses evaluateDeviation.
 */
import { dec, isDecimalString } from '../core/decimal';
import { Quantity } from '../core/quantity';
import { missing, ok, type Result } from '../core/result';
import { inputLeaf, traced, type Traced } from '../trace/trace';
import type { MissingData } from '../contract';
import { evaluateDeviation, type Deviation } from './deviation';

export const ACTUAL_COST_REASONS = {
  UNIT_COST_MISSING: 'production.unit_cost_missing',
  QUANTITY_INVALID: 'production.quantity_invalid',
  CONVERSION_MISSING: 'production.conversion_missing',
  UNITS_INVALID: 'production.units_invalid',
  NO_LINES: 'production.no_cost_lines',
} as const;

export interface ActualCostLine {
  id: string;
  quantity: string | null | undefined;
  /** The unit the quantity is in; defaults to the unit the cost is per. */
  quantityUnit?: 'g' | 'kg' | 'pc';
  /** EGP per `costUnit`; null = the lot has no cost on file. */
  unitCost: string | null | undefined;
  /** What the unit cost is per ('kg' for a material, 'pc' for a packaging piece). */
  costUnit?: 'kg' | 'pc';
}

export interface ActualBatchCostInput {
  materials: readonly ActualCostLine[];
  packaging: readonly ActualCostLine[];
  /** Actual conversion cost of the batch, EGP. */
  conversion: string | null | undefined;
  /** Finished units the batch produced. */
  units: string | null | undefined;
  /** The computed (approved) unit cost to compare with; absent = no comparison, reported as missing. */
  computedUnitCost?: string | null;
  thresholdPct?: string | null;
}

export interface ActualBatchCost {
  materials: Traced;
  packaging: Traced;
  conversion: Traced;
  total: Traced;
  perUnit: Traced;
  /** Per unit, actual against computed; null when no computed unit cost was given. */
  comparison: Deviation | null;
}

function sumLines(kind: string, lines: readonly ActualCostLine[], gaps: MissingData[]): Traced {
  const parts: Traced[] = [];
  let total = dec('0');
  for (const line of lines) {
    const id = `${kind}.${line.id}`;
    if (!isDecimalString(line.quantity ?? undefined) || dec(line.quantity as string).isNegative()) {
      gaps.push({ materialId: line.id, property: `${id}.quantity`, reason: ACTUAL_COST_REASONS.QUANTITY_INVALID });
      continue;
    }
    if (!isDecimalString(line.unitCost ?? undefined) || dec(line.unitCost as string).isNegative()) {
      gaps.push({ materialId: line.id, property: `${id}.unitCost`, reason: ACTUAL_COST_REASONS.UNIT_COST_MISSING });
      continue;
    }
    // A weight in g costed per kg is converted by the engine's own unit conversion (exact).
    const quantity = line.quantityUnit === 'g' && line.costUnit === 'kg' ? new Quantity(line.quantity as string, 'g').to('kg').value : dec(line.quantity as string);
    const cost = quantity.mul(line.unitCost as string);
    total = total.plus(cost);
    parts.push(traced('mul', `${id} = quantity x unit cost`, cost, 'EGP', [inputLeaf('Quantity', quantity, line.costUnit === 'kg' ? 'kg' : 'pc'), inputLeaf('Unit cost', line.unitCost as string, 'EGP')]));
  }
  return traced('sum', `Σ ${kind}`, total, 'EGP', parts);
}

export function actualBatchCost(input: ActualBatchCostInput): Result<ActualBatchCost> {
  if (input.materials.length === 0) {
    return missing([{ property: 'materials', reason: ACTUAL_COST_REASONS.NO_LINES }]);
  }
  const gaps: MissingData[] = [];
  const materials = sumLines('material', input.materials, gaps);
  const packaging = sumLines('packaging', input.packaging, gaps);
  const conversionOk = isDecimalString(input.conversion ?? undefined) && dec(input.conversion as string).gte(0);
  if (!conversionOk) {
    gaps.push({ property: 'conversion', reason: ACTUAL_COST_REASONS.CONVERSION_MISSING });
  }
  const unitsOk = isDecimalString(input.units ?? undefined) && dec(input.units as string).gt(0);
  if (!unitsOk) {
    gaps.push({ property: 'units', reason: ACTUAL_COST_REASONS.UNITS_INVALID });
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const conversion = inputLeaf('Actual conversion cost', input.conversion as string, 'EGP');
  const total = traced('sum', 'Actual batch cost', dec(materials.value).plus(packaging.value).plus(conversion.value), 'EGP', [materials, packaging, conversion]);
  const units = inputLeaf('Units produced', input.units as string, 'pc');
  const perUnit = traced('div', 'Actual cost per unit = total / units', dec(total.value).div(units.value), 'EGP', [total, units]);

  let comparison: Deviation | null = null;
  if (input.computedUnitCost !== undefined && input.computedUnitCost !== null) {
    const compared = evaluateDeviation({ planned: input.computedUnitCost, actual: perUnit.value, unit: 'EGP', thresholdPct: input.thresholdPct, property: 'unitCost' });
    if (!compared.ok) {
      return compared;
    }
    comparison = compared.value;
  }
  return ok({ materials, packaging, conversion, total, perUnit, comparison });
}

export interface InvoiceLineCostInput {
  /** Unit cost on the purchase line, EGP per purchase unit (a jerrycan, a bag). */
  unitCost: string | null | undefined;
  /** Quantity bought, in purchase units. */
  quantity: string | null | undefined;
  /** The same quantity in the product's base unit (kg for a raw material). */
  baseQuantity: string | null | undefined;
}

/**
 * Cost of ONE base unit (EGP per kg) of a lot, from the purchase line it came in on: unit cost x quantity / base quantity. It is the
 * invoice cost before tax and discount: freight and duties are not on a Sanad purchase line, so it is NOT a full landed cost.
 */
export function invoiceCostPerBaseUnit(input: InvoiceLineCostInput): Result<Traced> {
  const gaps: MissingData[] = [];
  if (!isDecimalString(input.unitCost ?? undefined) || dec(input.unitCost as string).isNegative()) {
    gaps.push({ property: 'unitCost', reason: ACTUAL_COST_REASONS.UNIT_COST_MISSING });
  }
  if (!isDecimalString(input.quantity ?? undefined) || dec(input.quantity as string).lte(0)) {
    gaps.push({ property: 'quantity', reason: ACTUAL_COST_REASONS.QUANTITY_INVALID });
  }
  if (!isDecimalString(input.baseQuantity ?? undefined) || dec(input.baseQuantity as string).lte(0)) {
    gaps.push({ property: 'baseQuantity', reason: ACTUAL_COST_REASONS.QUANTITY_INVALID });
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const total = traced('mul', 'Line cost = unit cost x quantity', dec(input.unitCost as string).mul(input.quantity as string), 'EGP', [inputLeaf('Unit cost', input.unitCost as string, 'EGP'), inputLeaf('Quantity', input.quantity as string, 'pc')]);
  return ok(traced('div', 'Cost per base unit = line cost / base quantity', dec(total.value).div(input.baseQuantity as string), 'EGP', [total, inputLeaf('Base quantity', input.baseQuantity as string, 'kg')]));
}
