import { ref } from 'vue';

/**
 * A list of existing values (functions, product types) fetched once, to suggest while typing in a free-text field.
 * The endpoint answers [{ value, label }]; a failure just means no suggestions.
 *
 *   const { suggestions, load } = useSuggestions(() => urls.functions);
 *   <Input list="functions" @focus="load" /> <datalist id="functions"><option v-for="s in suggestions" :value="s" /></datalist>
 */
export function useSuggestions(url) {
    const suggestions = ref([]);
    let loaded = false;

    async function load() {
        if (loaded) {
            return;
        }

        loaded = true;

        try {
            const response = await fetch(typeof url === 'function' ? url() : url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            suggestions.value = response.ok ? (await response.json()).map((option) => option.value) : [];
        } catch {
            suggestions.value = [];
        }
    }

    return { suggestions, load };
}
