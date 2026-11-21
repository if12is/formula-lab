/**
 * What every calculator shares between the form and the engine: turning typed text into what the engine takes and an
 * engine Result into one outcome shape the CalculatorLayout shows. The browser computes nothing here: a number on the
 * screen is a value the engine returned (or a node wrapped around one, see trace()).
 *
 * An outcome is { state, results, missing, warnings, extras }:
 *   state     'empty' (nothing typed yet) | 'missing' (the engine lists what it lacks) | 'ready'
 *   results   [{ key, traced, decimals?, params?, group?, primary?, kind?, status? }]  traced is the engine's { value, unit, trace }
 *   missing   [{ materialId?, property, reason }] straight from the engine
 *   warnings  [{ key, details[], raw }] from the engine's warning strings
 */
import { isDecimalString, qty } from '@fl/engine';
import { normalizeDecimalText } from '../engine';

export const EMPTY = Object.freeze({ state: 'empty', results: [], missing: [], warnings: [] });

/** An engine warning string "key:detail:detail" as { key, details, raw }. */
export const warningOf = (raw) => {
    const [key, ...details] = String(raw).split(':');

    return { key, details, raw: String(raw) };
};

/** An engine Result as an outcome; `build(value)` lists the result rows of a successful one. */
export function outcomeOf(result, build, scope = null) {
    const warnings = result.warnings.map(warningOf);

    if (!result.ok) {
        return { state: 'missing', results: [], missing: result.missing.map((gap) => ({ ...gap, scope })), warnings };
    }

    return { state: 'ready', results: build(result.value), missing: [], warnings };
}

/** Several engine Results at once: the rows of every ok one, and the gaps and warnings of all of them. */
export function mergeOutcomes(...outcomes) {
    const ready = outcomes.filter((outcome) => outcome.state === 'ready');
    const missing = outcomes.flatMap((outcome) => outcome.missing);
    const warnings = outcomes.flatMap((outcome) => outcome.warnings);

    return {
        state: missing.length ? 'missing' : 'ready',
        results: missing.length ? [] : ready.flatMap((outcome) => outcome.results),
        missing,
        warnings,
    };
}

/** True when a text field holds nothing. */
export const isBlank = (text) => text === '' || text === null || text === undefined;

/** A decimal typed by the person: Arabic digits turn into plain ones; nothing else changes (the engine judges it). */
export const typed = (text) => normalizeDecimalText(text);

/**
 * A { value, unit } field as an engine Quantity. Text that is not a decimal becomes zero of that unit on purpose: the
 * engine refuses a non-positive mass or volume with its own reason (input.invalid_mass), so the gap is the engine's.
 */
export function toQty(field) {
    const value = typed(field.value);

    return qty(isDecimalString(value) ? value : '0', field.unit);
}

/** A field that holds a decimal the engine will take as written, or null. */
export const decimalOrNull = (text) => (isDecimalString(typed(text)) ? typed(text) : null);

/** Result rows are [{ key, traced, ... }]; this one keeps the three that every row needs. */
export const row = (key, traced, decimals, extra = {}) => ({ key, traced, decimals, ...extra });
