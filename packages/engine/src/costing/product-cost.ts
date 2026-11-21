/**
 * Product cost per kg WITH its per-material breakdown (extends cost/material.ts; no second cost model).
 * The breakdown is what lets the USD exposure (I-06) say which materials are priced in USD and what a
 * single material price change does to the unit cost.
 */
import type { SourcedValue, TraceNode } from '../contract';
import type { Result } from '../core/result';
import { flatMap, ok } from '../core/result';
import { costPerNetKg } from '../cost/derived';
import { materialCostPerKg, type MaterialCostInput } from '../cost/material';
import { dec } from '../core/decimal';
import { yieldFraction } from '../batch/wastage';
import { currencyOf } from '../core/quantity';
import type { Traced } from '../trace/trace';
import { decOf, traced } from '../trace/trace';
import type { LineCurrency } from './line';

export interface MaterialContribution {
  materialId: string;
  /** EGP per kg of finished product this material contributes (wastage included). */
  perKg: Traced;
  /** Currency of the material's price before conversion. */
  currency: LineCurrency;
}

export interface ProductCostBreakdown {
  /** Cost per NET kg: Σ contributions. */
  costPerKg: Traced;
  contributions: MaterialContribution[];
}

/** Material cost per net kg (after `wastagePct`) and each material's share of it. */
export function productCostBreakdown(input: MaterialCostInput, wastagePct: string): Result<ProductCostBreakdown> {
  const material = materialCostPerKg(input);
  return flatMap(material, (perKg) =>
    flatMap(costPerNetKg(perKg, wastagePct), (net) =>
      flatMap(yieldFraction(wastagePct), (y) => {
        const contributions = input.lines.map((line, i): MaterialContribution => {
          const term = perKg.trace.inputs[i] as TraceNode;
          const price = input.prices[line.materialId] as SourcedValue;
          return {
            materialId: line.materialId,
            perKg: traced('div', `${line.materialId} per net kg = term ÷ (1 − w/100)`, dec(term.value).div(y), 'EGP', [{ value: term.value, unit: term.unit, trace: term }]),
            currency: currencyOf(price.unit) === 'USD' ? 'USD' : 'EGP',
          };
        });
        return ok({ costPerKg: net, contributions });
      }),
    ),
  );
}

/** Σ of the contributions as a decimal, for consistency checks. */
export function contributionsTotal(items: readonly MaterialContribution[]) {
  return items.reduce((acc, c) => acc.plus(decOf(c.perKg)), dec('0'));
}
