/**
 * Entry point of the child process of T-04: prints the corpus digests, calculate()'s hashes and the runtime facts as one JSON line.
 * Started as `node --experimental-transform-types --import ./register.mjs run-corpus.ts` (see determinism.test.ts).
 */
import { calculateHashes, computeCorpus, runtimeInfo } from './corpus';

process.stdout.write(`${JSON.stringify({ corpus: computeCorpus(), calculate: calculateHashes(), runtime: runtimeInfo() })}\n`);
