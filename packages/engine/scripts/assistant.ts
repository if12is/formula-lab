/**
 * The engine calls behind the Claude Haiku assistant's tools (P-02, P-11): unit conversion, the compliance check, the air-care blend and the phase check of an emulsion. The assistant only
 * relays an answer; every number in it comes from the engine functions below.
 * The Laravel app calls this from App\Support\Engine\EngineRunner.
 *
 * stdin : JSON `{ "op": "convert-units" | "check-compliance" | "air-care-blend" | "formula-phases", "input": { ... } }`
 *   convert-units   : { value, from, to, density?: { value, unit, sourceId, confidence }, fxRate?: FxRate }
 *   check-compliance: { lines: [{ materialId, percentWW }], materials: [composition], ruleSets: [RuleSet], context: ProductContext }
 *   air-care-blend  : { fragranceId?, fragranceOilPct, otherPct?, batchMassG, ifraLimitPct?: SourcedScalar }
 *   formula-phases  : { lines: [{ materialId, percentWW?, qs?, phase?, additionTempC? }] }
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
import { airCareBlend, type AirCareBlendInput } from '../src/fragrance';
import { balanceQs, phaseSummary, validatePhases, type PhasedLine } from '../src/formula';
import { qty } from '../src/core/quantity';

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

interface BlendRequest {
  fragranceId?: string;
  fragranceOilPct: string;
  otherPct?: string;
  batchMassG: string;
  ifraLimitPct?: AirCareBlendInput['ifraLimitPct'];
}

/** Oil + other + carrier of an air-care product (L-07). A dose is never offered here: it is a sourced value the assistant cannot invent. */
function blend(input: BlendRequest): Result<unknown> {
  const result = airCareBlend({
    fragranceId: input.fragranceId,
    fragranceOilPct: input.fragranceOilPct,
    otherPct: input.otherPct,
    batchMass: qty(input.batchMassG, 'g'),
    ifraLimitPct: input.ifraLimitPct,
    policy: {},
  });
  if (!result.ok) {
    return result;
  }
  const part = (p: { key: string; pct: { value: string; unit: string }; mass: { value: string; unit: string } }) => ({
    key: p.key,
    pct: { value: p.pct.value, unit: p.pct.unit },
    mass: { value: p.mass.value, unit: p.mass.unit },
  });
  const blended = result.value;
  const ifra = blended.ifra;
  return ok(
    {
      fragranceOil: part(blended.fragranceOil),
      carrier: part(blended.remainder),
      totalMass: { value: blended.totalMass.value, unit: blended.totalMass.unit },
      ifra: {
        status: ifra.status,
        limitPct: ifra.limitPct === undefined ? null : { value: ifra.limitPct.value, unit: ifra.limitPct.unit, sourceId: ifra.limitPct.trace.sourceId, confidence: ifra.limitPct.trace.confidence },
        marginPct: ifra.marginPct === undefined ? null : { value: ifra.marginPct.value, unit: ifra.marginPct.unit },
        missing: ifra.missing,
      },
    },
    result.warnings,
  );
}

interface PhasesRequest {
  lines: Array<{ materialId: string; percentWW?: string; qs?: boolean; phase?: string | null; additionTempC?: string | null }>;
}

/** What a phase problem is about, for the gap list (the engine's message key is the reason). */
function phaseProperty(code: string): string {
  if (code === 'PHASE_SUM_NOT_100') return 'phaseSum';
  if (code.startsWith('PHASE_TEMP')) return 'additionTempC';
  if (code.startsWith('PHASE_')) return 'phase';
  return code.startsWith('QS_') ? 'qs' : 'percentWW';
}

/** The phase sums of a formula (M-01); a q.s. line is balanced first. Every problem is a gap; a formula with no phase on any line has none to sum. */
function formulaPhases(input: PhasesRequest): Result<unknown> {
  let lines = input.lines.map((l) => ({ materialId: l.materialId, percentWW: l.percentWW ?? '', phase: l.phase, additionTempC: l.additionTempC }));
  if (input.lines.some((l) => l.qs === true)) {
    const balanced = balanceQs(input.lines);
    if (!balanced.ok) {
      return missing(balanced.errors.map((e) => ({ materialId: e.materialId, property: phaseProperty(e.code), reason: e.messageKey })));
    }
    const percents = new Map(balanced.value.lines.map((l) => [l.materialId, l.percentWW]));
    lines = lines.map((l) => ({ ...l, percentWW: percents.get(l.materialId) ?? l.percentWW }));
  }
  const phased: PhasedLine[] = lines;
  if (!phased.some((l) => l.phase !== undefined && l.phase !== null && l.phase !== '')) {
    return missing([{ property: 'phase', reason: 'formula.phase_missing' }]);
  }
  const validated = validatePhases(phased);
  if (!validated.ok) {
    return missing(validated.errors.map((e) => ({ ...(e.materialId === undefined ? {} : { materialId: e.materialId }), property: phaseProperty(e.code), reason: e.messageKey })));
  }
  return ok({ ...phaseSummary(phased), lines: phased.length }, validated.warnings);
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
  case 'air-care-blend':
    answer = blend(request.input as BlendRequest);
    break;
  case 'formula-phases':
    answer = formulaPhases(request.input as PhasesRequest);
    break;
  default:
    console.error(`assistant: unknown op ${JSON.stringify(request.op)}.`);
    process.exit(2);
}

process.stdout.write(JSON.stringify(answer.ok ? { ok: true, value: answer.value, warnings: answer.warnings } : { ok: false, missing: answer.missing, warnings: answer.warnings }));
