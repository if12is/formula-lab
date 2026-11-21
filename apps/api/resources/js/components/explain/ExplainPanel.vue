<script setup>
/**
 * Explains a figure: opens the engine's calculation tree (TraceNode) for it, in a kit Drawer from the inline-end edge
 * on a desktop and as a bottom sheet on a phone. Read-only: it shows what the engine did, the browser adds nothing.
 *
 *   <ExplainPanel v-model:open="open" :traced="{ value, unit, trace }" :title="t('...')" :entities="..." :sources="..." />
 *
 * Normally used through <ExplainableNum>, which owns the open state.
 */
import { Drawer } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { useIsMobile } from '../../composables/useIsMobile';
import Num from '../Num.vue';
import TraceTree from './TraceTree.vue';

defineProps({
    traced: { type: Object, required: true },
    /** What is being explained, e.g. "Cost per kg". */
    title: { type: String, default: null },
    decimals: { type: Number, default: undefined },
    entities: { type: Object, default: () => ({}) },
    sources: { type: Object, default: () => ({}) },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t, has } = useI18n();
const isMobile = useIsMobile();
const unitLabel = (unit) => (unit && has(`calculators.units.${unit}`) ? t(`calculators.units.${unit}`) : unit);
</script>

<template>
    <Drawer v-model:open="open" :title="t('calculators.explain.title')" :description="title ?? undefined" :side="isMobile ? 'bottom' : 'end'" size="lg">
        <div class="flex flex-col gap-4" data-testid="explain-panel">
            <div class="rounded-lg border border-line bg-surface-2 p-4">
                <p class="text-label text-ink-3">{{ t('calculators.explain.result') }}</p>
                <p class="type-metric text-ink">
                    <Num :value="traced.value" :decimals="decimals" /> <span class="text-label font-normal text-ink-3">{{ unitLabel(traced.unit) }}</span>
                </p>
            </div>
            <p class="text-label text-ink-3">{{ t('calculators.explain.intro') }}</p>
            <TraceTree :trace="traced.trace" :entities="entities" :sources="sources" />
        </div>
    </Drawer>
</template>
