/**
 * Option lists come from the server as { value, label } (data, e.g. a supplier name) or
 * { value, labelKey } (a lang key, e.g. an enum case). The kit's controls want plain { value, label }.
 * One place does that conversion for ListFilters and EntitySelect.
 */
export function resolveOptions(options, t) {
    return (options ?? []).map(({ labelKey, label, ...rest }) => ({ ...rest, label: label ?? (labelKey ? t(labelKey) : String(rest.value)) }));
}
