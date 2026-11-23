<script setup>
/**
 * Q-09: the real cost of the batch against the computed unit cost, from the engine: weighed materials at the cost of their lots, packaging
 * at its real cost and the conversion cost spent, per unit. A missing cost, conversion or unit count stops the calculation and is named.
 * Shown only to a person who sees prices.
 */
import { Alert, Badge, Card, DescriptionList } from '@kit';
import { computed } from 'vue';
import Num from '../Num.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    cost: { type: Object, required: true },
});

const { t } = useI18n();
const TONES = { none: 'success', within: 'success', exceeds: 'danger', threshold_not_set: 'warning' };

const items = computed(() => {
    const result = props.cost.result;

    return result ? [
        { term: t('production.cost.materials'), key: 'materials' },
        { term: t('production.cost.packaging'), key: 'packaging' },
        { term: t('production.cost.conversion'), key: 'conversion' },
        { term: t('production.cost.total'), key: 'total' },
        { term: t('production.cost.per_unit'), key: 'per_unit' },
    ] : [];
});
</script>

<template>
    <Card :title="t('production.cost.title')" :description="t('production.cost.description')" data-testid="cost-card">
        <Alert v-if="!cost.ok" tone="warning" icon="ph:warning" :title="t('production.cost.missing_title')" data-testid="cost-missing">
            <ul class="list-disc ps-5"><li v-for="gap in cost.missing" :key="gap">{{ gap }}</li></ul>
        </Alert>
        <div v-else class="flex flex-col gap-3" data-testid="cost-result">
            <DescriptionList :items="items" :columns="3">
                <template #materials><Num :value="cost.result.materials.value" :decimals="2"><template #suffix> {{ t('production.units.egp') }}</template></Num></template>
                <template #packaging><Num :value="cost.result.packaging.value" :decimals="2"><template #suffix> {{ t('production.units.egp') }}</template></Num></template>
                <template #conversion><Num :value="cost.result.conversion.value" :decimals="2"><template #suffix> {{ t('production.units.egp') }}</template></Num></template>
                <template #total><Num :value="cost.result.total.value" :decimals="2"><template #suffix> {{ t('production.units.egp') }}</template></Num></template>
                <template #per_unit><span data-result="actual_unit_cost"><Num :value="cost.result.perUnit.value" :decimals="4"><template #suffix> {{ t('production.units.egp') }}</template></Num></span></template>
            </DescriptionList>
            <div v-if="cost.result.comparison" class="flex flex-wrap items-center gap-x-6 gap-y-2" data-testid="cost-comparison" :data-verdict="cost.result.comparison.verdict">
                <span>{{ t('production.cost.computed') }}: <Num :value="cost.result.comparison.planned" :decimals="4"><template #suffix> {{ t('production.units.egp') }}</template></Num></span>
                <span>{{ t('production.cost.difference') }}: <Num :value="cost.result.comparison.difference.value" :decimals="4"><template #suffix> {{ t('production.units.egp') }}</template></Num> (<Num :value="cost.result.comparison.differencePctDisplay"><template #suffix> %</template></Num>)</span>
                <Badge :tone="TONES[cost.result.comparison.verdict]" dot>{{ t(`enums.deviation_verdict.${cost.result.comparison.verdict}`) }}</Badge>
            </div>
            <p v-else class="text-label text-ink-3">{{ t('production.cost.no_computed') }}</p>
            <p class="text-label text-ink-3">{{ t('production.cost.invoice_note') }}</p>
        </div>
    </Card>
</template>
