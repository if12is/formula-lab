import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/** Fills {name} placeholders: fill('{count} filters', { count: 3 }) -> '3 filters'. */
function fill(text, params) {
    return params ? text.replace(/\{(\w+)\}/g, (match, name) => (name in params ? String(params[name]) : match)) : text;
}

/**
 * The frontend's only way to print UI text. Strings live in lang/<locale>/*.php and arrive as
 * the shared Inertia prop `translations` ("group.key" -> text) for the active locale, so there is
 * one dictionary for server and client and a locale switch needs no rebuild.
 *
 *   const { t } = useI18n();
 *   t('nav.dashboard');
 *   t('filters.active_count', { count: 3 });
 *
 * A missing key prints the key itself (visible in review) and warns once in development.
 */
const warned = new Set();

export function useI18n() {
    const page = usePage();
    const dictionary = computed(() => page.props.translations ?? {});

    const t = (key, params) => {
        const text = dictionary.value[key];

        if (text === undefined) {
            if (import.meta.env.DEV && !warned.has(key)) {
                warned.add(key);
                console.warn(`[i18n] missing translation key: ${key}`);
            }

            return key;
        }

        return fill(text, params);
    };

    return {
        t,
        has: (key) => key in dictionary.value,
        locale: computed(() => page.props.locale?.current ?? 'ar'),
        direction: computed(() => page.props.locale?.direction ?? 'rtl'),
        // Latin digits by default. A user preference can share locale.numbering = 'arab' later.
        numbering: computed(() => page.props.locale?.numbering ?? 'latn'),
    };
}
