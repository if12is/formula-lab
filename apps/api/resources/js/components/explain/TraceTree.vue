<script setup>
/**
 * An engine TraceNode tree (a result and every step and input behind it) as a tree: the operation, the step's formula
 * as the engine labels it, the value with its unit through <Num>, and for each input where it came from: its source
 * (with a link when the source or material can be opened) and its confidence badge, L7 highlighted.
 *
 * Built on the kit's TreeView: the row, indent, caret and keyboard are the kit's, and the `#item` slot draws a row's
 * content, which here needs components (Num, ConfidenceBadge, links).
 *
 *   <TraceTree :trace="traced.trace" :entities="{ 'Olive oil': { href: '/materials/…' } }" :sources="{ [id]: { title, href } }" />
 */
import { computed } from 'vue';
import { Badge, TreeView } from '@kit';
import { useI18n } from '../../composables/useI18n';
import ConfidenceBadge from '../materials/ConfidenceBadge.vue';
import Num from '../Num.vue';

const props = defineProps({
    trace: { type: Object, required: true },
    /** What the engine called a record in its labels -> { label?, href }: that text becomes the record's name, as a link. */
    entities: { type: Object, default: () => ({}) },
    /** sourceId -> { title, href }: what a leaf's source is called, and where it can be opened. */
    sources: { type: Object, default: () => ({}) },
});

const { t, has } = useI18n();

const toItem = (node, id) => {
    const children = node.inputs.map((input, index) => toItem(input, `${id}.${index}`));

    return { id, node, ...(children.length ? { children } : {}) };
};
const isLeaf = (item) => !item.children;
const items = computed(() => [toItem(props.trace, 'n')]);
const expanded = computed(() => {
    const ids = [];
    const walk = (item) => {
        if (item.children) {
            ids.push(item.id);
            item.children.forEach(walk);
        }
    };
    items.value.forEach(walk);

    return ids;
});

const opLabel = (op) => (has(`calculators.trace.ops.${op}`) ? t(`calculators.trace.ops.${op}`) : op);
const unitLabel = (unit) => (unit && has(`calculators.units.${unit}`) ? t(`calculators.units.${unit}`) : unit);

/** A label split into text and entity links: [{ text, href? }]; a record's own name replaces the engine's id for it. */
function segments(label) {
    const names = Object.keys(props.entities).filter((name) => name !== '').sort((a, b) => b.length - a.length);

    if (!names.length) {
        return [{ text: label }];
    }

    const pattern = new RegExp(`(${names.map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`);

    return label.split(pattern).filter((part) => part !== '').map((part) => ({ text: props.entities[part]?.label ?? part, href: props.entities[part]?.href ?? null }));
}

const sourceOf = (node) => props.sources[node.sourceId] ?? null;
const isEstimate = (node) => node.confidence === 'L7_estimate';
</script>

<template>
    <TreeView :items="items" :default-expanded="expanded" :aria-label="t('calculators.explain.tree')" data-testid="trace-tree">
        <template #item="{ item }">
            <span
                class="flex min-w-0 flex-1 flex-col gap-1 rounded-md py-1.5"
                :class="isEstimate(item.node) && 'bg-danger-soft'"
                :data-trace-op="item.node.op"
                :data-trace-leaf="isLeaf(item) ? 'true' : undefined"
            >
                <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <Badge tone="neutral" size="sm">{{ opLabel(item.node.op) }}</Badge>
                    <span class="min-w-0 break-words">
                        <bdi dir="ltr"><template v-for="(part, index) in segments(item.node.label)" :key="index"><a v-if="part.href" :href="part.href" target="_blank" rel="noopener" class="text-primary-ink underline underline-offset-2">{{ part.text }}</a><template v-else>{{ part.text }}</template></template></bdi>
                    </span>
                </span>
                <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-semibold text-ink">
                        <Num :value="item.node.value" :options="{ maximumFractionDigits: 6 }" /> <span class="font-normal text-ink-3">{{ unitLabel(item.node.unit) }}</span>
                    </span>
                    <template v-if="isLeaf(item)">
                        <span v-if="item.node.sourceId" class="flex flex-wrap items-center gap-2 text-label text-ink-3" data-testid="trace-source">
                            {{ t('calculators.trace.source') }}
                            <a v-if="sourceOf(item.node)?.href" :href="sourceOf(item.node).href" target="_blank" rel="noopener" class="text-primary-ink underline underline-offset-2">{{ sourceOf(item.node).title ?? item.node.sourceId }}</a>
                            <span v-else class="text-ink-2">{{ sourceOf(item.node)?.title ?? item.node.sourceId }}</span>
                            <ConfidenceBadge v-if="item.node.confidence" :confidence="item.node.confidence" />
                            <span v-if="isEstimate(item.node)" class="font-semibold text-danger-ink">{{ t('calculators.trace.estimate') }}</span>
                        </span>
                        <span v-else-if="item.node.op === 'input'" class="text-label text-ink-3">{{ t('calculators.trace.user_input') }}</span>
                    </template>
                </span>
            </span>
        </template>
    </TreeView>
</template>
