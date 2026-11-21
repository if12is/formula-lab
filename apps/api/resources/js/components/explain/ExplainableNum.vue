<script setup>
/**
 * A figure the person can ask "how was this worked out?" about: <Num> as a button that opens the ExplainPanel with the
 * engine's calculation tree. Without a trace (nothing computed) it is a plain <Num> that says why it is missing.
 *
 *   <ExplainableNum :traced="cost.perKgTraced" :decimals="2" :label="t('formulas.totals.cost')" :reason-key="costGapReason" />
 *   <ExplainableNum :value="sum" :trace="sumTraced?.trace" :decimals="3" :label="t('formulas.totals.sum')" />
 *
 * `traced` is an engine { value, unit, trace }; or give `value` and `trace` separately. `entities` and `sources` make the
 * panel's labels and leaves resolvable (see TraceTree). Slots #prefix / #suffix pass through to <Num>.
 */
import { computed, ref } from 'vue';
import { Icon } from '@kit';
import { useI18n } from '../../composables/useI18n';
import Num from '../Num.vue';
import ExplainPanel from './ExplainPanel.vue';

const props = defineProps({
    traced: { type: Object, default: null },
    value: { type: [Number, String], default: null },
    trace: { type: Object, default: null },
    unit: { type: String, default: '' },
    decimals: { type: Number, default: undefined },
    options: { type: Object, default: () => ({}) },
    reasonKey: { type: String, default: 'common.no_data_yet' },
    /** Names the figure for the button and the panel. */
    label: { type: String, default: null },
    entities: { type: Object, default: () => ({}) },
    sources: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const open = ref(false);
const shownValue = computed(() => props.traced?.value ?? props.value);
const shownTrace = computed(() => props.traced?.trace ?? props.trace);
const panelTraced = computed(() => ({ value: shownValue.value, unit: props.traced?.unit ?? props.unit, trace: shownTrace.value }));
const explainable = computed(() => shownTrace.value !== null && shownTrace.value !== undefined && shownValue.value !== null && shownValue.value !== '');
</script>

<template>
    <template v-if="explainable">
        <button
            type="button"
            class="inline-flex min-h-6 cursor-help items-baseline gap-1 rounded-sm underline decoration-ink-4 decoration-dotted underline-offset-4 outline-none hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring [@media(pointer:coarse)]:min-h-11 [@media(pointer:coarse)]:items-center"
            :aria-label="t('calculators.explain.open', { label: label ?? '' })"
            data-testid="explain-trigger"
            @click="open = true"
        >
            <Num :value="shownValue" :decimals="decimals" :options="options"><template #prefix><slot name="prefix" /></template><template #suffix><slot name="suffix" /></template></Num>
            <Icon name="ph:info" size="sm" class="self-center text-ink-3" aria-hidden="true" />
        </button>
        <ExplainPanel v-model:open="open" :traced="panelTraced" :title="label" :decimals="decimals" :entities="entities" :sources="sources" />
    </template>
    <Num v-else :value="shownValue" :decimals="decimals" :options="options" :reason-key="reasonKey"><template #prefix><slot name="prefix" /></template><template #suffix><slot name="suffix" /></template></Num>
</template>
