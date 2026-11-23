/**
 * The engine calls behind the Claude Haiku assistant's tools (P-02): unit conversion and the compliance check. The assistant only
 * relays an answer; every number in it comes from the engine functions below.
 * The Laravel app calls this from App\Support\Engine\EngineRunner.
 *
 * stdin : JSON `{ "op": "convert-units" | "check-compliance", "input": { ... } }`
 *   convert-units   : { value, from, to, density?: { value, unit, sourceId, confidence }, fxRate?: FxRate }
 *   check-compliance: { lines: [{ materialId, percentWW }], materials: [composition], ruleSets: [RuleSet], context: ProductContext }
 * stdout: JSON `{ ok: true, value, warnings }` or `{ ok: false, missing, warnings }`
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/assistant.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or the op is unknown.
 */
import { vvFromWW, wwFromVV, SOURCE as OIML_SOURCE } from '../src/core/alcohol';
import { dec } from '../src/core/decimal';
import { convertFromEgp, convertMoney, type FxRate } from '../src/core/fx';
import { massToVolume, volumeToMass, type DensityRef } from '../src/core/convert';
import { currencyOf, dimensionOf, isUnit, Quantity, type Currency, type Unit } from '../src/core/quantity';
import { missing, ok, type Result } from '../src/core/result';
import { evaluateCompliance, overallStatus, type FormulaComposition, type ProductContext } from '../src/compliance';
import type { RuleSet } from '../src/compliance';

/** The decimals kept in a converted figure (the Sanad contract's 6); a longer exact value is rounded half-even, once, here. */
const OUTPUT_DECIMALS = 6;

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

interface ConvertInput {
  value: string;
  from: string;
  to: string;
  density?: DensityRef;
  fxRate?: FxRate;
}

interface Converted {
  value: string;
  unit: Unit;
  rounded: boolean;
  basis: { kind: string; sourceId?: string; confidence?: string; rate?: string; date?: string };
}

function present(value: ReturnType<typeof dec>, unit: Unit, basis: Converted['basis']): Converted {
  const exact = value.toFixed();
  const rounded = value.decimalPlaces() > OUTPUT_DECIMALS;
  const shown = rounded ? value.toDecimalPlaces(OUTPUT_DECIMALS, 6 /* ROUND_HALF_EVEN */).toFixed() : exact;
  return { value: shown, unit, rounded, basis };
}

function convertUnits(input: ConvertInput): Result<Converted> {
  if (!isUnit(input.from) || !isUnit(input.to)) {
    return missing([{ property: isUnit(input.from) ? 'to' : 'from', reason: 'unit.unknown' }]);
  }
  const from = input.from;
  const to = input.to;
  let amount: Quantity;
  try {
    amount = new Quantity(input.value, from);
  } catch {
    return missing([{ property: 'value', reason: 'input.invalid_number' }]);
  }
  const fromDimension = dimensionOf(from);
  const toDimension = dimensionOf(to);

  // Alcohol strength by the OIML R 22 table (never a formula of ours).
  if ((from === '%v/v' && to === '%w/w') || (from === '%w/w' && to === '%v/v')) {
    const converted = from === '%v/v' ? wwFromVV(amount.value.toFixed()) : vvFromWW(amount.value.toFixed());
    return converted.ok ? ok(present(converted.value, to, { kind: 'oiml_r22', sourceId: OIML_SOURCE.id }), converted.warnings) : converted;
  }

  if (fromDimension === 'money' && toDimension === 'money') {
    const fx = input.fxRate;
    const source = currencyOf(from) as Currency;
    const target = currencyOf(to) as Currency;
    if (source === target) {
      return ok(present(amount.value, to, { kind: 'identity' }));
    }
    const result = target === 'EGP' ? convertMoney(amount, 'EGP', fx) : source === 'EGP' ? convertFromEgp(amount, target, fx) : null;
    if (result === null) {
      return missing([{ property: `fx:${source}->${target}`, reason: 'fx.direct_rate_only' }]);
    }
    return result.ok
      ? ok(present(result.value.amount.value, to, { kind: 'fx', rate: result.value.rate, date: result.value.date, sourceId: result.value.sourceId }), result.warnings)
      : result;
  }

  if (fromDimension === toDimension && fromDimension !== 'money') {
    try {
      return ok(present(amount.to(to).value, to, { kind: 'unit_table' }));
    } catch {
      return missing([{ property: 'to', reason: 'unit.incompatible' }]);
    }
  }

  const massToVol = fromDimension === 'mass' && toDimension === 'volume';
  const volToMass = fromDimension === 'volume' && toDimension === 'mass';
  if (massToVol || volToMass) {
    const converted = massToVol
      ? massToVolume(amount as never, input.density, to as never)
      : volumeToMass(amount as never, input.density, to as never);
    const basis = { kind: 'explicit_density', ...(input.density === undefined ? {} : { sourceId: input.density.sourceId, confidence: input.density.confidence }) };
    return converted.ok ? ok(present(converted.value.value, to, basis), converted.warnings) : converted;
  }
  return missing([{ property: 'to', reason: 'unit.incompatible' }]);
}

interface ComplianceInput {
  lines: FormulaComposition['lines'];
  materials: FormulaComposition['materials'];
  ruleSets: RuleSet[];
  context: ProductContext;
}

/** One line per rule: the status, the value with its unit, the limit, and where the rule comes from (the trace tree stays in the engine). */
function checkCompliance(input: ComplianceInput): Result<unknown> {
  const reports = input.ruleSets.map((ruleSet) => {
    const evaluated = evaluateCompliance({ lines: input.lines, materials: input.materials }, ruleSet, input.context);
    if (!evaluated.ok) {
      return { ruleSetId: ruleSet.id, overall: 'UNKNOWN' as const, valid: false, rules: [], missing: evaluated.missing };
    }
    const report = evaluated.value;
    return {
      ruleSetId: report.ruleSetId,
      ruleSetVersion: report.ruleSetVersion,
      overall: report.overall,
      valid: true,
      warnings: report.warnings,
      rules: report.results
        .filter((r) => r.status !== 'NOT_APPLICABLE')
        .map((r) => ({
          ruleId: r.ruleId,
          status: r.status,
          value: r.value === undefined ? null : r.value.value,
          valueComplete: r.valueComplete,
          limit: r.limit,
          unit: r.unit,
          sourceId: r.sourceId,
          messageKey: r.messageKey,
          missing: r.missing,
        })),
      missing: report.missing,
    };
  });
  const statuses = reports.map((r) => r.overall);
  return ok({ overall: statuses.length === 0 ? 'NOT_APPLICABLE' : overallStatus(statuses), reports });
}

let request: { op?: unknown; input?: unknown };
try {
  request = JSON.parse(await readStdin()) as { op?: unknown; input?: unknown };
} catch {
  console.error('assistant: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof request !== 'object' || request === null || typeof request.input !== 'object' || request.input === null) {
  console.error('assistant: expected {"op": "...", "input": {...}} on stdin.');
  process.exit(2);
}

let answer: Result<unknown>;
switch (request.op) {
  case 'convert-units':
    answer = convertUnits(request.input as ConvertInput);
    break;
  case 'check-compliance':
    answer = checkCompliance(request.input as ComplianceInput);
    break;
  default:
    console.error(`assistant: unknown op ${JSON.stringify(request.op)}.`);
    process.exit(2);
}

process.stdout.write(JSON.stringify(answer.ok ? { ok: true, value: answer.value, warnings: answer.warnings } : { ok: false, missing: answer.missing, warnings: answer.warnings }));
