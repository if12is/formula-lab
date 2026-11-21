// Prints {canonical, sha256} for a JSON document using the TS engine's own canonicalJson/sha256Hex.
// Usage: node --experimental-strip-types engine-canonical.mjs <file.json>
import { readFileSync } from 'node:fs';
import { canonicalJson, sha256Hex } from '../../../../packages/engine/src/hash.ts';

const value = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const canonical = canonicalJson(value);
process.stdout.write(JSON.stringify({ canonical, sha256: sha256Hex(canonical) }));
