<script setup>
/**
 * The one selector for picking a record (material, supplier, formula, substance...). It wraps the kit's
 * Combobox for a list already on the page and the kit's Typeahead for a list searched on the server, and
 * puts either inside a FormField with label, hint and error. All text comes from lang keys.
 *
 *   <EntitySelect v-model="form.material_id" label-key="formulas.fields.material" :options="materials" :error="form.errors.material_id" required />
 *   <EntitySelect v-model="form.supplier_id" label-key="..." search-url="/suppliers/search" :selected-label="supplier?.name" />
 *   <EntitySelect v-model="form.tags" label-key="..." :options="tags" multiple />
 *
 * Local mode: `options` is [{ value, label | labelKey, hint? }]; the box filters as you type; `multiple` allows many.
 * Remote mode: `search-url` answers GET ?q=text with JSON [{ value, label, description? }]; the chosen value
 * is the model, and `selected-label` names the current value on first paint (an edit form). Any other keys of
 * an option travel with it to the `select` event, so a page can keep what the server sent along (a material's prices).
 *
 * `bare` drops the FormField for a control inside a table row or a repeated line: the label then becomes
 * the control's aria-label (say which row in the label, e.g. through `label-params`).
 */
import { computed, ref, watch } from 'vue';
import { Combobox, FormField, Typeahead } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { resolveOptions } from '../../lib/options';

const props = defineProps({
    labelKey: { type: String, required: true },
    hintKey: { type: String, default: null },
    placeholderKey: { type: String, default: 'common.search_placeholder' },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    options: { type: Array, default: () => [] },
    multiple: { type: Boolean, default: false },
    searchUrl: { type: String, default: null },
    selectedLabel: { type: String, default: '' },
    name: { type: String, default: undefined },
    bare: { type: Boolean, default: false },
    /** Placeholders for the label's {name} slots, e.g. { line: 3 }. */
    labelParams: { type: Object, default: undefined },
});

const model = defineModel({ type: [String, Number, Array], default: null });
const emit = defineEmits(['select']);
const { t } = useI18n();

const localOptions = computed(() => resolveOptions(props.options, t));
const placeholder = computed(() => t(props.placeholderKey));

// Remote mode: the text in the box is separate from the chosen value.
const text = ref(props.selectedLabel);
watch(() => props.selectedLabel, (label) => { text.value = label; });

async function search(query, { signal }) {
    const url = new URL(props.searchUrl, window.location.origin);
    url.searchParams.set('q', query);

    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });

    if (!response.ok) {
        throw new Error(String(response.status));
    }

    return resolveOptions(await response.json(), t);
}

function picked(option) {
    model.value = option.value;
    emit('select', option);
}

// Emptying the box clears the choice, so a stale id is never submitted.
watch(text, (value) => {
    if (props.searchUrl && value === '' && model.value !== null && model.value !== '') {
        model.value = null;
    }
});
</script>

<template>
    <component
        :is="bare ? 'div' : FormField"
        v-bind="bare ? {} : { label: t(labelKey, labelParams), hint: hintKey ? t(hintKey) : undefined, error: error ?? undefined, required }"
    >
        <Typeahead
            v-if="searchUrl"
            v-model="text"
            :search="search"
            :free="false"
            :disabled="disabled"
            :placeholder="placeholder"
            :name="name"
            :aria-label="bare ? t(labelKey, labelParams) : undefined"
            :invalid="bare && Boolean(error)"
            @select="picked"
        />
        <Combobox
            v-else
            v-model="model"
            :options="localOptions"
            :multiple="multiple"
            :disabled="disabled"
            :placeholder="placeholder"
            :name="name"
            :aria-label="bare ? t(labelKey, labelParams) : undefined"
            :invalid="bare && Boolean(error)"
        />
    </component>
</template>
