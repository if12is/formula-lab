import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from './useI18n';

/**
 * The material property registry (config/properties.php, shared by the server as `propertyRegistry`): the one place
 * that turns a property key into its name and lists the units it may be stored in.
 *
 *   const { label, options, unitsOf } = useProperties();
 *   label('active_matter')    the property's name in the active language (an unregistered legacy key prints as it is)
 *   options.value             [{ value, label }] for a Select
 *   unitsOf('density')        ['g/mL', 'kg/L']
 */
export function useProperties() {
    const page = usePage();
    const { t, has } = useI18n();
    const registry = computed(() => page.props.propertyRegistry ?? []);
    const entry = (key) => registry.value.find((candidate) => candidate.value === key);

    return {
        registry,
        label: (key) => {
            const known = entry(key);

            return known && has(known.labelKey) ? t(known.labelKey) : key;
        },
        options: computed(() => registry.value.map((candidate) => ({ value: candidate.value, label: t(candidate.labelKey) }))),
        unitsOf: (key) => entry(key)?.units ?? [],
    };
}
