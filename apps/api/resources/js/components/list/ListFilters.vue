<script setup>
/**
 * The control row above every list: search, filters, sort and "clear all", driven by the schema the
 * server sends (App\Http\Filters\ListQuery) and the state held by useListQuery. Built on the kit's
 * FilterBar, so chips, number ranges, the active count, Clear all, keyboard handling and the filtered-empty flow are the kit's. Write a list page
 * with this and nothing else; never assemble a FilterBar by hand.
 *
 *   const query = useListQuery(props.list.schema);
 *   <ListFilters :query="query" />
 *
 * Schema entries: { key, type: 'select' | 'multiselect' | 'boolean' | 'date-range' | 'number-range',
 *   labelKey, options: [{ value, label | labelKey }] | optionsUrl }. A long option list is fetched once
 * from `optionsUrl` ([{ value, label }] as JSON). The sort control lists schema.sorts.
 */
import { computed, onMounted, reactive } from 'vue';
import { FilterBar, IconButton, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { resolveOptions } from '../../lib/options';

const props = defineProps({
    query: { type: Object, required: true },
    /** How many filters stay as buttons; the rest move under "More filters" (FilterBar's `visible`). */
    visible: { type: Number, default: null },
});

const { t } = useI18n();
const schema = computed(() => props.query.schema);
const remote = reactive({});

onMounted(() => {
    schema.value.filters.filter((filter) => filter.optionsUrl).forEach(async (filter) => {
        try {
            const response = await fetch(filter.optionsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            remote[filter.key] = response.ok ? await response.json() : [];
        } catch {
            remote[filter.key] = [];
        }
    });
});

const KIT_TYPES = { boolean: 'flag', 'date-range': 'date-range', 'number-range': 'number-range' };

/** The schema in the shape of the kit's FilterBar `filters`. */
const barFilters = computed(() => schema.value.filters
    .map((filter) => ({
        key: filter.key,
        type: KIT_TYPES[filter.type],
        label: t(filter.labelKey),
        chip: filter.type === 'boolean' ? t(filter.labelKey) : undefined,
        multiple: filter.type === 'multiselect',
        options: resolveOptions(filter.options ?? remote[filter.key], t),
    })));

const sortOptions = computed(() => schema.value.sorts.map((sort) => ({ value: sort.key, label: t(sort.labelKey) })));
const sortKey = computed({
    get: () => props.query.sort.value.key,
    set: (key) => { props.query.sort.value = { key, dir: props.query.sort.value.dir }; },
});
const descending = computed(() => props.query.sort.value.dir === 'desc');

function toggleDirection() {
    props.query.sort.value = { key: sortKey.value, dir: descending.value ? 'asc' : 'desc' };
}
</script>

<template>
    <FilterBar
        v-model:search="query.search.value"
        v-model:values="query.filterValues.value"
        :filters="barFilters"
        :no-search="!schema.search"
        :search-placeholder="schema.search ? t(schema.search.placeholderKey) : undefined"
        :busy="query.busy.value"
        :visible="visible"
    >
        <template #end>
            <Select
                v-if="sortOptions.length"
                v-model="sortKey"
                :options="sortOptions"
                size="sm"
                icon="ph:arrows-down-up"
                class="w-44"
                :aria-label="t('filters.sort_by')"
            />
            <IconButton
                v-if="sortOptions.length"
                :icon="descending ? 'ph:sort-descending' : 'ph:sort-ascending'"
                :label="descending ? t('filters.sort_direction_desc') : t('filters.sort_direction_asc')"
                variant="secondary"
                size="sm"
                @click="toggleDirection"
            />

        </template>
    </FilterBar>
</template>
