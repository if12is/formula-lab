/**
 * The determinism corpus (T-04): a fixed set of engine computations reduced to SHA-256 digests.
 * Imported by the vitest test (in-process) and run by run-corpus.ts (fresh `node` process), so both sides compute the same list.
 *
 * What is digested: the VALUES the engine returns (decimal strings, statuses, missing[] reasons), plus the engine's own
 * inputHash/outputHash for calculate(). Trace labels are left out of the pinned digests so a wording change in a trace does not
 * break the pinned file; calculate().outputHash (trace included) is still compared between processes, just not pinned.
 * No clock, no randomness, no locale: if any of those leaked in, two processes would disagree.
 */
import {
  ENGINE_VERSION,
  activeMatterOfBlend,
  availableChlorineDilution,
  backwardWaterfall,
  calculate,
  canonicalJson,
  diluteEthanol,
  disinfectantActive,
  emulsifierSystem,
  fineFragrance,
  forwardWaterfall,
  neutralize,
  qty,
  rawMassForTarget,
  reagentForTargetPH,
  roundToStep,
  saltForViscosity,
  sha256Hex,
  soapLye,
  viscosityAt,
  weighingSheet,
  type EngineInput,
} from '../../src/index';
import { fullCost } from '../../src/costing/test-fixtures';
import { fixture } from '../../src/test-fixtures';
import { inputLeaf } from '../../src/trace/trace';

/** JSON with sorted keys that also allows numbers (the engine's canonicalJson refuses them on purpose). */
function stable(value: unknown): string {
  const plain: unknown = JSON.parse(JSON.stringify(value));
  const walk = (v: unknown): unknown => {
    if (Array.isArray(v)) {
      return v.map(walk);
    }
    if (v !== null && typeof v === 'object') {
      return Object.fromEntries(Object.keys(v as object).sort().map((k) => [k, walk((v as Record<string, unknown>)[k])]));
    }
    return v;
  };
  return JSON.stringify(walk(plain));
}

const digest = (value: unknown): string => sha256Hex(stable(value));

/** Value-only view of a Result: the traced numbers become their `value` strings, everything else is kept. */
function values(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(values);
  }
  if (value !== null && typeof value === 'object') {
    const o = value as Record<string, unknown>;
    if (typeof o.value === 'string' && 'trace' in o) {
      return o.value;
    }
    return Object.fromEntries(Object.entries(o).map(([k, v]) => [k, values(v)]));
  }
  return value;
}

const sv = (value: string, unit: 'EGP' | 'USD' | 'kg/L', id: string) => ({ value, unit, sourceId: id, confidence: 'L2_supplier_tds' as const, validFrom: '2026-01-01' });

export const CALCULATE_INPUT: EngineInput = {
  engineVersion: ENGINE_VERSION,
  datasetVersion: 'determinism-v1',
  formula: {
    lines: [{ materialId: 'A', percentWW: '50' }, { materialId: 'B', percentWW: '30' }, { materialId: 'C', percentWW: '20' }],
    productDensity: sv('1.25', 'kg/L', 'LAB-RHO'),
  },
  batch: { size: { value: '80', unit: 'kg' }, wastagePct: '20', packSize: { value: '400', unit: 'g' } },
  prices: { A: sv('20', 'EGP', 'P-A'), B: sv('1.5', 'USD', 'P-B'), C: sv('0.5', 'EGP', 'P-C') },
  packaging: { perPack: sv('1.2', 'EGP', 'P-PACK') },
  pricing: { marginPct: '20' },
  fxRates: [{ from: 'USD', to: 'EGP', rate: '40', date: '2026-02-01', sourceId: 'FX-FICTIONAL', source: 'TEST' }],
  policy: { rounding: 'default' },
};

const vat = { productFamilyId: 'FAM', taxType: 'vat', ratePct: '14', validFrom: '2026-01-01', sourceId: 'TAX-TEST', confidence: 'L4_regulatory_db' } as const;
const tax = { setting: { mode: 'rate', ratePct: '14' } as const, regimes: [vat], productFamilyId: 'FAM', taxType: 'vat', asOf: '2026-06-01' };
const lab = (id: string) => ({ sourceId: id, confidence: 'L1_coa_batch' as const });

/** name -> thunk returning what to digest. Every entry is independent. */
const entries: Record<string, () => unknown> = {
  'calculate.inputHash': () => calculate(CALCULATE_INPUT).inputHash,
  'calculate.results': () => Object.fromEntries(Object.entries(calculate(CALCULATE_INPUT).results).map(([k, v]) => [k, v.value])),
  'calculate.missing': () => calculate({ ...CALCULATE_INPUT, prices: { A: CALCULATE_INPUT.prices.A! } }).missing,
  soap: () =>
    values(
      soapLye({
        oils: [
          { oilId: 'OIL-A', mass: qty('600', 'g'), sap: { unit: 'mgKOH/g', typ: fixture('190'), min: fixture('188'), max: fixture('192') } },
          { oilId: 'OIL-B', mass: qty('400', 'g'), sap: { unit: 'gNaOH/g', typ: fixture('0.128'), min: fixture('0.126'), max: fixture('0.130') } },
        ],
        base: 'NaOH',
        purity: fixture('97', '%w/w', 'L1_coa_batch'),
        superfatPct: '5',
        water: { method: 'lyeConcentration', value: '30' },
        policy: {},
      }),
    ),
  'active.blend': () => values(activeMatterOfBlend({ lines: [{ materialId: 'A', mass: qty('300', 'g'), contributesActive: true, activeMatter: fixture('70', '%w/w') }, { materialId: 'W', mass: qty('700', 'g'), contributesActive: false, activeMatter: fixture('0', '%w/w') }], policy: {} })),
  'active.dilution': () => values(rawMassForTarget({ batchMass: qty('7000', 'g'), targetActivePct: '13', rawActive: fixture('70', '%w/w'), policy: {} })),
  'chlorine.storage': () =>
    values(
      availableChlorineDilution({
        coa: fixture('12.7', '%w/w', 'L1_coa_batch'),
        coaBasis: 'NaOCl',
        targetPct: '3.3',
        targetBasis: 'Cl2',
        batchMass: qty('3000', 'g'),
        storage: { lossPoints: [{ days: '0', lossPct: fixture('0', '%w/w') }, { days: '30', lossPct: fixture('17', '%w/w') }], days: '11' },
        policy: {},
      }),
    ),
  neutralize: () => values(neutralize({ acidMass: qty('1234.5', 'g'), acidValue: fixture('187.3', 'mgKOH/g'), base: 'NaOH', form: 'solution', strength: fixture('47.9', '%w/w'), policy: {} })),
  hlb: () =>
    values(
      emulsifierSystem({
        oilLines: [{ materialId: 'O1', mass: '37', phase: 'oil', requiredHLB: fixture('9.3') }, { materialId: 'O2', mass: '21', phase: 'oil', requiredHLB: fixture('13.1') }],
        low: { materialId: 'LOW', hlb: fixture('4.3'), ionicity: 'nonionic' },
        high: { materialId: 'HIGH', hlb: fixture('15'), ionicity: 'nonionic' },
        totalMass: qty('183', 'g'),
      }),
    ),
  // the next three use the SHIPPED OIML R 22 table: they exercise the JSON data path and non-terminating divisions
  'fine.fragrance': () => values(fineFragrance({ fragranceOilPct: '17.3', fixativePct: '1.7', alcoholSource: fixture('96.2', '%v/v', 'L1_coa_batch'), alcoholTargetPctVV: '81.4', batch: { mass: qty('1337', 'g') }, ifraLimitPct: fixture('21', '%w/w'), policy: {} })),
  'alcohol.dilution': () => values(diluteEthanol({ source: fixture('95.6', '%v/v', 'L1_coa_batch'), targetPctVV: '70', batch: { volume: qty('1000', 'mL') }, policy: {} })),
  disinfectant: () => values(disinfectantActive({ kind: 'alcohol', lines: [{ materialId: 'E', mass: qty('730', 'g'), contributesEthanol: true, strength: fixture('96', '%v/v', 'L1_coa_batch') }, { materialId: 'W', mass: qty('270', 'g'), contributesEthanol: false }], policy: {}, requiredMinPctVV: '70' })),
  'unit.cost': () => {
    const c = fullCost();
    return values({ fillKg: c.fillKg, sections: c.sections, total: c.total });
  },
  'waterfall.forward': () => values(forwardWaterfall({ unitCost: inputLeaf('Unit cost', '17.585', 'EGP'), marginBasis: 'margin_on_price', factoryMarginPct: '23', distributionCostPerUnit: '1.7', wholesalerMarginPct: '9', retailerMarginPct: '17', returnsPct: '2.5', tax, policy: {} })),
  'waterfall.backward': () => values(backwardWaterfall({ shelfPriceInclVat: '73.9', vatRatePct: '14', retailerMarginPct: '17', wholesalerMarginPct: '9', distributionCostPerUnit: '1.7', factoryMarginPct: '23', marginBasis: 'margin_on_price', fillKg: '0.35', nonFormulaCostPerUnit: '4.1' })),
  weighing: () =>
    weighingSheet({
      batchMass: { value: '777', unit: 'kg' },
      resolutionG: '0.5',
      lines: [
        { lineNo: '1', materialId: 'WATER', phase: 'water', qs: true },
        { lineNo: '2', materialId: 'SLES', phase: 'water', percentWW: '12.345' },
        { lineNo: '3', materialId: 'OIL', phase: 'oil', percentWW: '7.777' },
        { lineNo: '4', materialId: 'PERF', phase: 'cool_down', percentWW: '0.3' },
      ],
    }),
  curves: () => ({
    salt: [values(viscosityAt([{ saltPct: '0', viscosity_mPas: '100', ...lab('L1') }, { saltPct: '2', viscosity_mPas: '5100', ...lab('L2') }, { saltPct: '4', viscosity_mPas: '12100', ...lab('L3') }], '2.7')), values(saltForViscosity([{ saltPct: '0', viscosity_mPas: '100', ...lab('L1') }, { saltPct: '2', viscosity_mPas: '5100', ...lab('L2') }, { saltPct: '4', viscosity_mPas: '12100', ...lab('L3') }], '9001'))],
    titration: values(reagentForTargetPH({ curve: { reagentId: 'ACID', unit: 'pctWW', points: [{ added: '0', pH: '9.1', ...lab('T1') }, { added: '1.3', pH: '7.9', ...lab('T2') }, { added: '2.9', pH: '6.2', ...lab('T3') }] }, targetPH: '7.1', batchMass: qty('1500', 'g') })),
  }),
  // trip wires for the two things a runtime can get wrong: number formatting and sort/case rules
  'format.trip': () => [roundToStep('1234567.891', '0.01'), roundToStep('0.0000001', '0.0000001'), roundToStep('2.5', '1'), canonicalJson({ i: 'I', 'ı': 'x', z: 'Z', İ: 'y', a: ['b', 'ä'] })],
};

export const CORPUS_NAMES = Object.keys(entries);

/** name -> SHA-256 of the entry's canonical content. */
export function computeCorpus(): Record<string, string> {
  return Object.fromEntries(CORPUS_NAMES.map((name) => [name, digest((entries[name] as () => unknown)())]));
}

/** calculate()'s own hashes (trace included): compared across processes, not pinned in the repo. */
export function calculateHashes(): { inputHash: string; outputHash: string } {
  const out = calculate(CALCULATE_INPUT);
  return { inputHash: out.inputHash, outputHash: out.outputHash };
}

/** What this runtime says about itself, so a test can prove that the environment variation really took effect. */
export function runtimeInfo(): { timeZone: string; locale: string; offsetMinutes: number; node: string; numberSample: string } {
  const resolved = Intl.DateTimeFormat().resolvedOptions();
  return {
    timeZone: resolved.timeZone,
    locale: resolved.locale,
    // an arbitrary fixed instant: its offset differs per zone, so a leaked Date would show up
    offsetMinutes: new Date('2026-07-01T00:00:00Z').getTimezoneOffset(),
    node: process.version,
    numberSample: (1234567.891).toLocaleString(),
  };
}
