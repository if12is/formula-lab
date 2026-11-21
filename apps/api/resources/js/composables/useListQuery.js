import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useQueryState } from '@kit';

/**
 * Every list page's state in one place: search, filters, sort, page and page size, kept in the URL
 * (the kit's useQueryState) and applied by the server (App\Http\Filters\ListQuery) through an
 * Inertia reload. Pages never wire their own watchers.
 *
 *   const props = defineProps({ list: Object });
 *   const query = useListQuery(props.list.schema);
 *   <ListFilters :query="query" />
 *   <DataTable :rows="list.rows" v-model:sort="query.sort.value" :busy="query.busy.value" />
 *   <Pagination v-model:page="query.page.value" v-model:per-page="query.per.value" :total="list.meta.total" />
 *
 * URL keys match ListQuery: search, <filter>, <range>_from/_to or _min/_max, sort, dir, page, per.
 * A value equal to its default stays out of the URL. Typing in search writes after a short pause.
 */

const inFlight = ref(0);
const busy = computed(() => inFlight.value > 0);

/**
 * createKit({ replaceQuery }): the kit rewrites the address through this, so a list state change
 * becomes an Inertia GET to the same path. State is kept, scroll is kept, history is replaced.
 */
export function inertiaReplaceQuery(query) {
    router.get(window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        showProgress: false,
        onStart: () => { inFlight.value += 1; },
        onFinish: () => { inFlight.value = Math.max(0, inFlight.value - 1); },
    });
}

const RANGE_SUFFIXES = {
    'date-range': ['_from', '_to'],
    'number-range': ['_min', '_max'],
};

/** The query-string defaults for a schema: the type of each default decides how the kit parses the URL. */
function defaultsFor(schema) {
    const defaults = { search: '', sort: schema.defaults.sort, dir: schema.defaults.dir, page: 1, per: schema.defaults.per };

    for (const filter of schema.filters) {
        if (RANGE_SUFFIXES[filter.type]) {
            RANGE_SUFFIXES[filter.type].forEach((suffix) => { defaults[filter.key + suffix] = ''; });
        } else if (filter.type === 'multiselect') {
            defaults[filter.key] = [];
        } else if (filter.type === 'boolean') {
            defaults[filter.key] = false;
        } else {
            defaults[filter.key] = '';
        }
    }

    return defaults;
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

export function useListQuery(schema) {
    const state = useQueryState(defaultsFor(schema));
    const resetPage = () => { state.page = 1; };

    /** Sets a state key; a change to anything but the page itself sends the list back to page 1. */
    const assign = (key, value) => {
        if (!same(state[key], value)) {
            state[key] = value;
            resetPage();
        }
    };

    const search = computed({
        get: () => state.search,
        set: (value) => assign('search', value ?? ''),
    });

    const sort = computed({
        get: () => ({ key: state.sort, dir: state.dir }),
        // A cleared sort (a third click on a header) returns to the default order.
        set: (next) => {
            assign('sort', next?.key ?? schema.defaults.sort);
            assign('dir', next?.key ? next.dir ?? 'asc' : schema.defaults.dir);
        },
    });

    const page = computed({ get: () => state.page, set: (value) => { state.page = value; } });
    const per = computed({ get: () => state.per, set: (value) => assign('per', value) });

    /** Filters in the shape of the kit's FilterBar `values`: a date range is { start, end }, a number range [from, to]; both are null when empty. */
    const filterValues = computed({
        get: () => Object.fromEntries(schema.filters.map((filter) => {
            if (filter.type === 'number-range') {
                const range = ['_min', '_max'].map((suffix) => (state[filter.key + suffix] === '' ? null : Number(state[filter.key + suffix])));

                return [filter.key, range.some((end) => end !== null) ? range : null];
            }

            if (filter.type === 'date-range') {
                const [from, to] = RANGE_SUFFIXES['date-range'].map((suffix) => state[filter.key + suffix]);

                return [filter.key, from || to ? { start: from || null, end: to || null } : null];
            }

            return [filter.key, filter.type === 'multiselect' ? state[filter.key] : state[filter.key] || null];
        })),
        set: (next) => {
            for (const filter of schema.filters) {
                const value = next[filter.key];

                if (filter.type === 'number-range') {
                    const [from, to] = value ?? [null, null];

                    assign(`${filter.key}_min`, from === null || from === undefined ? '' : String(from));
                    assign(`${filter.key}_max`, to === null || to === undefined ? '' : String(to));
                } else if (filter.type === 'date-range') {
                    assign(filter.key + '_from', value?.start ?? '');
                    assign(filter.key + '_to', value?.end ?? '');
                } else if (filter.type === 'multiselect') {
                    assign(filter.key, value ?? []);
                } else if (filter.type === 'boolean') {
                    assign(filter.key, Boolean(value));
                } else if (filter.type === 'select') {
                    assign(filter.key, value ?? '');
                }
            }
        },
    });

    const isActive = (filter) => {
        if (RANGE_SUFFIXES[filter.type]) {
            return RANGE_SUFFIXES[filter.type].some((suffix) => state[filter.key + suffix] !== '');
        }

        return filter.type === 'multiselect' ? state[filter.key].length > 0 : Boolean(state[filter.key]);
    };

    const activeCount = computed(() => (state.search ? 1 : 0) + schema.filters.filter(isActive).length);

    /** Clears search and every filter; sort and page size stay. */
    function clear() {
        const keys = ['search'];

        for (const filter of schema.filters) {
            keys.push(...(RANGE_SUFFIXES[filter.type] ? RANGE_SUFFIXES[filter.type].map((suffix) => filter.key + suffix) : [filter.key]));
        }

        const blank = defaultsFor(schema);
        keys.forEach((key) => { state[key] = blank[key]; });
        resetPage();
    }

    return { schema, state, search, sort, page, per, filterValues, isActive, activeCount, clear, busy };
}
