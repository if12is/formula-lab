/**
 * Engine contract as code. Spec: plan/02-specs/engine-contract.md (see "Versioning").
 * Every number crossing a JSON boundary is a decimal string.
 */
import type { Quantity, QuantityJSON, Unit } from './core/quantity';

export const ENGINE_VERSION = '0.1.0';

export type Confidence =
  | 'L1_coa_batch'
  | 'L2_supplier_tds'
  | 'L3_standard'
  | 'L4_regulatory_db'
  | 'L5_reference'
  | 'L6_community'
  | 'L7_estimate';

export interface SourcedValue {
  /** Decimal as string. */
  value: string;
  unit: Unit;
  min?: string;
  max?: string;
  sourceId: string;
  confidence: Confidence;
  /** ISO date (YYYY-MM-DD). */
  validFrom: string;
}

export interface MissingData {
  materialId?: string;
  property: string;
  reason: string;
}

export interface TraceNode {
  op: string;
  label: string;
  value: string;
  unit: Unit;
  inputs: TraceNode[];
  sourceId?: string;
  confidence?: Confidence;
}

export interface EngineInput {
  /** Pinned semver. */
  engineVersion: string;
  /** Hash of the reference data snapshot. */
  datasetVersion: string;
  formula: {
    lines: { materialId: string; percentWW: string }[];
    productDensity?: SourcedValue;
  };
  batch?: {
    /** kg or L */
    size: QuantityJSON | Quantity;
    wastagePct: string;
    packSize: QuantityJSON | Quantity;
    packCost: string;
  };
  /** materialId -> dated EGP/kg price. */
  prices: Record<string, SourcedValue>;
  policy: { allowConfidenceBelow?: Confidence; rounding: 'default' };
}

export interface EngineOutput {
  /** false whenever missing is non-empty. */
  ok: boolean;
  missing: MissingData[];
  results: Record<string, { value: string; unit: Unit; trace: TraceNode }>;
  warnings: string[];
  inputHash: string;
  outputHash: string;
  engineVersion: string;
  datasetVersion: string;
}
