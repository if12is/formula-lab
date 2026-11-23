/**
 * T-04: the same inputs give the same hashes wherever the engine runs.
 *
 * COVERED here (and only this):
 *   1. Node in-process (the vitest worker) against the pinned digests in corpus.hashes.json.
 *   2. A FRESH `node` child process per environment variant (UTC/C, a UTC+14 zone with an Arabic locale, a UTC−3:30 zone with a
 *      Turkish locale (dotted/dotless i), and V8 with its optimising compilers switched off: --no-opt --no-maglev --no-sparkplug; --jitless cannot be used because Node's own
 *      TypeScript stripping needs WebAssembly), each compared digest-by-digest with the in-process result
 *      and with the pinned file. The child proves the variation took effect (its own timeZone / locale / offset are asserted).
 *   3. calculate().inputHash and outputHash (trace included) identical across all of the above.
 *
 * NOT COVERED (pending S-09, the Electron shell and the web build): the same corpus run inside Electron's Node/V8 and inside a
 * browser engine. This test is the contract those runtimes must meet: they import computeCorpus()/calculateHashes() from
 * ./corpus and must equal corpus.hashes.json. Until then "same hash on web/Electron/Node" is verified for Node only.
 */
import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { calculateHashes, computeCorpus, CORPUS_NAMES } from './corpus';

const here = dirname(fileURLToPath(import.meta.url));
const PINNED = join(here, 'corpus.hashes.json');

interface ChildReport {
  corpus: Record<string, string>;
  calculate: { inputHash: string; outputHash: string };
  runtime: { timeZone: string; locale: string; offsetMinutes: number; node: string; numberSample: string };
}

interface Variant {
  name: string;
  env: Record<string, string>;
  nodeArgs?: string[];
  expect: { timeZone: string; offsetMinutes: number; localePrefix?: string };
}

const VARIANTS: Variant[] = [
  { name: 'UTC / C locale', env: { TZ: 'UTC', LANG: 'C', LC_ALL: 'C' }, expect: { timeZone: 'UTC', offsetMinutes: 0 } },
  { name: 'Pacific/Kiritimati (UTC+14) / ar-EG', env: { TZ: 'Pacific/Kiritimati', LANG: 'ar_EG.UTF-8', LC_ALL: 'ar_EG.UTF-8' }, expect: { timeZone: 'Pacific/Kiritimati', offsetMinutes: -840, localePrefix: 'ar' } },
  { name: 'America/St_Johns (UTC-3:30, DST) / tr-TR', env: { TZ: 'America/St_Johns', LANG: 'tr_TR.UTF-8', LC_ALL: 'tr_TR.UTF-8' }, expect: { timeZone: 'America/St_Johns', offsetMinutes: 150, localePrefix: 'tr' } },
  { name: 'Africa/Cairo / en-US, V8 interpreter only', env: { TZ: 'Africa/Cairo', LANG: 'en_US.UTF-8', LC_ALL: 'en_US.UTF-8' }, nodeArgs: ['--no-opt', '--no-maglev', '--no-sparkplug'], expect: { timeZone: 'Africa/Cairo', offsetMinutes: -180 } },
];

function runChild(variant: Variant): ChildReport {
  const result = spawnSync(
    process.execPath,
    ['--experimental-transform-types', '--disable-warning=ExperimentalWarning', ...(variant.nodeArgs ?? []), '--import', join(here, 'register.mjs'), join(here, 'run-corpus.ts')],
    // a clean environment: only PATH and the variant's own variables, so nothing inherited from the test runner leaks in
    { env: { PATH: process.env.PATH ?? '', ...variant.env }, encoding: 'utf8', timeout: 120_000 },
  );
  if (result.status !== 0) {
    throw new Error(`child (${variant.name}) failed: status ${String(result.status)}\n${result.stderr}`);
  }
  return JSON.parse(result.stdout.trim().split('\n').pop() as string) as ChildReport;
}

const inProcess = computeCorpus();
const inProcessCalculate = calculateHashes();

describe('T-04 determinism: pinned digests (Node, in-process)', () => {
  const pinned = JSON.parse(readFileSync(PINNED, 'utf8')) as Record<string, string>;

  it('the pinned file covers exactly the corpus entries (a new entry must be pinned, a removed one dropped)', () => {
    expect(Object.keys(pinned).sort()).toEqual([...CORPUS_NAMES].sort());
  });

  it.each(CORPUS_NAMES)('%s matches its pinned SHA-256', (name) => {
    expect(inProcess[name]).toBe(pinned[name]);
  });

  it('computing the corpus twice in the same process gives the same digests', () => {
    expect(computeCorpus()).toEqual(inProcess);
    expect(calculateHashes()).toEqual(inProcessCalculate);
  });
});

describe('T-04 determinism: fresh child processes in different time zone / locale / JIT environments', () => {
  for (const variant of VARIANTS) {
    it(`${variant.name}: every digest and both calculate() hashes equal the in-process ones`, () => {
      const report = runChild(variant);
      // the variation really happened in the child (otherwise equality proves nothing)
      expect(report.runtime.timeZone).toBe(variant.expect.timeZone);
      expect(report.runtime.offsetMinutes).toBe(variant.expect.offsetMinutes);
      if (variant.expect.localePrefix !== undefined) {
        expect(report.runtime.locale.startsWith(variant.expect.localePrefix)).toBe(true);
      }
      expect(report.corpus).toEqual(inProcess);
      expect(report.calculate).toEqual(inProcessCalculate);
    }, 130_000);
  }

  it('the runtimes differ from each other (the environments really are different), yet produce one set of digests', () => {
    const reports = VARIANTS.map(runChild);
    expect(new Set(reports.map((r) => r.runtime.timeZone)).size).toBe(VARIANTS.length);
    expect(new Set(reports.map((r) => r.runtime.numberSample)).size).toBeGreaterThan(1);
    expect(new Set(reports.map((r) => JSON.stringify(r.corpus))).size).toBe(1);
    expect(new Set(reports.map((r) => JSON.stringify(r.calculate))).size).toBe(1);
  }, 400_000);
});

// Regenerate the pinned file deliberately: `UPDATE_DETERMINISM=1 pnpm --filter @fl/engine test tests/determinism`.
// A changed digest means the engine's numbers changed: bump ENGINE_VERSION and say why in the commit.
if (process.env.UPDATE_DETERMINISM === '1') {
  writeFileSync(PINNED, `${JSON.stringify(inProcess, null, 2)}\n`);
}
