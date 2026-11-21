/**
 * A figure that needs a source (CLAUDE.md rule 2): value, unit, source, confidence level and the date it is valid from.
 * The date is the source's own (its retrieved_at), so a person picks a source rather than typing a date.
 *
 * A field is { value, unit, sourceId, sourceTitle, confidence, validFrom, fromMaterial }. toSourced() turns it into what
 * the engine takes; until it holds a value AND its source, confidence and date it is `undefined`, so the engine reports
 * `input.missing` instead of using half a datum.
 */
import { resolvedProperty } from '../engine';
import { typed } from './common';

export const blankSourced = (unit = '') => ({ value: '', unit, sourceId: null, sourceTitle: '', confidence: null, validFrom: null, fromMaterial: null });

/**
 * @param {object} field   a blankSourced()-shaped field
 * @param {{ withUnit?: boolean }} options  withUnit: the engine checks the unit (density, alcohol strength); false leaves it off
 */
export function toSourced(field, { withUnit = false } = {}) {
    if (!field || field.value === '' || !field.sourceId || !field.confidence || !field.validFrom) {
        return undefined;
    }

    return {
        value: typed(field.value),
        ...(withUnit && field.unit ? { unit: field.unit } : {}),
        sourceId: field.sourceId,
        confidence: field.confidence,
        validFrom: field.validFrom,
    };
}

/** A text typed with a sourced value's own provenance, for a min/max that comes from the same document. */
export function withProvenanceOf(field, text, options) {
    const base = toSourced(field, options);

    return base && text !== '' ? { ...base, value: typed(text) } : undefined;
}

/**
 * Fills a field with what the engine would use for a property of a material (resolveValue), through the material
 * option's property rows. Returns true when the material had a value; the field then says where it came from.
 */
export function fillFromMaterial(field, option, property) {
    const found = resolvedProperty(option.properties ?? [], property);

    if (!found || found.candidate.value === undefined) {
        return false;
    }

    const { candidate, row: source } = found;
    Object.assign(field, {
        value: candidate.value,
        unit: candidate.unit,
        sourceId: candidate.sourceId,
        sourceTitle: source?.source_title ?? '',
        confidence: candidate.confidence,
        validFrom: candidate.validFrom,
        fromMaterial: { id: option.value, label: option.label },
    });

    return true;
}

/** The sources named by the given fields, as { [sourceId]: { title, href? } } for the explain panel. */
export function sourcesOf(fields, hrefOf) {
    return Object.fromEntries(
        fields
            .filter((field) => field?.sourceId)
            .map((field) => [field.sourceId, { title: field.sourceTitle || null, href: field.fromMaterial ? hrefOf(field.fromMaterial.id) : null }]),
    );
}
