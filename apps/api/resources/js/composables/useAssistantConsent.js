import { reactive, readonly } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * The privacy consent of this browser session (P-10), one shared state for every place that asks for it (the assistant panel, the COA upload).
 * The server is the source of truth: this only reads and writes through the consent endpoints shared in the `assistant` page prop, so a page
 * reload, another tab or an expiry is always reflected after `load()`.
 */
const state = reactive({ loaded: false, busy: false, failed: false, granted: false, categories: [], expiresAt: null });

function apply(data) {
    state.granted = Boolean(data.granted);
    state.categories = data.categories ?? [];
    state.expiresAt = data.expiresAt ?? null;
    state.loaded = true;
    state.failed = false;
}

export function useAssistantConsent() {
    const page = usePage();
    const url = () => page.props.assistant?.consentUrl;

    async function call(method, data) {
        state.busy = true;
        try {
            const response = await window.axios({ method, url: url(), data, headers: { Accept: 'application/json' } });
            apply(response.data);

            return true;
        } catch {
            state.failed = true;

            return false;
        } finally {
            state.busy = false;
        }
    }

    return {
        state: readonly(state),
        load: () => call('get'),
        grant: (categories) => call('post', { categories }),
        revoke: () => call('delete'),
        /** True when the session has consented to every one of these categories. */
        allows: (categories) => state.granted && categories.every((category) => state.categories.includes(category)),
    };
}
