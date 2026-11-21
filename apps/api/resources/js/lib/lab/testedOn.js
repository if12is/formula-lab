/**
 * "Tested on formula X" tags (O-07) through the engine's testedOnTags: a value a screen is using is tagged only when the lab
 * recorded the SAME number for the same material and kind. The records come with the material option from the server
 * (`testedMarks`); the browser decides nothing about them.
 */
import { isDecimalString, testedOnTags } from '@fl/engine';
import { typed } from '../calculators/common';

/** Tags of one used value of a picked material option ({ value: id, testedMarks }); [] when nothing matches. */
export function tagsFor(option, kind, text) {
    const value = typed(text ?? '');

    if (!option || !isDecimalString(value)) {
        return [];
    }

    return testedOnTags([{ kind, materialId: option.value, value }], option.testedMarks ?? []);
}
