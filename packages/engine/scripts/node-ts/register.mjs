/** `node --experimental-transform-types --import ./register.mjs script.ts` runs an engine script (see hooks.mjs). */
import { register } from 'node:module';

register('./hooks.mjs', import.meta.url);
