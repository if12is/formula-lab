<script setup>
/**
 * Q-03: the alkali (lye of a soap, base of a neutralisation) recomputed with the COA values of the lots really weighed, against the same
 * masses at the formula's reference values; both are the engine's soap and neutralisation functions. A lot with no COA value stops the
 * calculation and is named (never replaced by the reference value). Superfat is a formulation choice typed here, not stored.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, EmptyState } from '@kit';
import DecimalField from '../form/DecimalField.vue';
import Num from '../Num.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    alkali: { type: Object, required: true },
    url: { type: String, required: true },
});

const { t } = useI18n();
const superfat = ref(props.alkali.superfat_pct ?? '');
const TONES = { none: 'success', within: 'success', exceeds: 'danger', threshold_not_set: 'warning' };

function compute() {
    router.get(props.url, { superfat_pct: superfat.value }, { preserveScroll: true, preserveState: true, only: ['alkali'], replace: true });
}
</script>

<template>
    <Card :title="t('production.alkali.title')" :description="t('production.alkali.description')" data-testid="alkali-card">
        <EmptyState v-if="!alkali.applicable" variant="dashed" icon="ph:flask" :title="t('production.alkali.not_applicable')" />
        <div v-else class="flex flex-col gap-3">
            <p class="text-ink-2">{{ t(`production.alkali.kind.${alkali.kind}`, { base: alkali.base }) }}</p>

            <form v-if="alkali.kind === 'soap'" class="flex flex-wrap items-end gap-3" novalidate data-testid="alkali-form" @submit.prevent="compute">
                <DecimalField v-model="superfat" label-key="production.alkali.superfat" hint-key="production.alkali.superfat_hint" suffix="%" />
                <Button type="submit" variant="secondary" icon="ph:calculator" data-testid="alkali-compute">{{ t('production.alkali.compute') }}</Button>
            </form>

            <Alert v-if="alkali.missing.length" tone="warning" icon="ph:warning" :title="t('production.alkali.missing_title')" data-testid="alkali-missing">
                <ul class="list-disc ps-5"><li v-for="gap in alkali.missing" :key="gap">{{ gap }}</li></ul>
            </Alert>
            <p v-else-if="alkali.needs === 'superfat'" class="text-label text-ink-3" data-testid="alkali-needs">{{ t('production.alkali.needs_superfat') }}</p>

            <div v-if="alkali.result" class="flex flex-col gap-2" data-testid="alkali-result" :data-verdict="alkali.result.deviation.verdict">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><p class="text-label text-ink-3">{{ t('production.alkali.planned') }}</p><p class="type-metric text-ink" data-result="alkali_planned"><Num :value="alkali.result.plannedG"><template #suffix> {{ t('production.units.g') }}</template></Num></p></div>
                    <div><p class="text-label text-ink-3">{{ t('production.alkali.lot') }}</p><p class="type-metric text-ink" data-result="alkali_lot"><Num :value="alkali.result.lotG"><template #suffix> {{ t('production.units.g') }}</template></Num></p></div>
                    <div>
                        <p class="text-label text-ink-3">{{ t('production.alkali.difference') }}</p>
                        <p class="type-metric text-ink" data-result="alkali_difference"><Num :value="alkali.result.deviation.difference.value"><template #suffix> {{ t('production.units.g') }}</template></Num></p>
                        <p class="text-label text-ink-2"><Num :value="alkali.result.deviation.differencePctDisplay"><template #suffix> %</template></Num> <Badge :tone="TONES[alkali.result.deviation.verdict]" dot>{{ t(`enums.deviation_verdict.${alkali.result.deviation.verdict}`) }}</Badge></p>
                    </div>
                </div>
                <p class="text-label text-ink-3">{{ t('production.alkali.theory_note') }}</p>
            </div>
        </div>

        <div v-if="alkali.lots.length" class="mt-4 flex flex-col gap-2" data-testid="coa-lots">
            <p class="font-semibold text-ink">{{ t('production.alkali.lots_title') }}</p>
            <ul class="flex flex-col gap-1">
                <li v-for="lot in alkali.lots" :key="lot.line_no" class="flex flex-wrap items-center gap-2 text-label" :data-lot="lot.lot">
                    <span class="text-ink">{{ lot.material }}</span>
                    <Badge tone="neutral" mono>{{ lot.lot }}</Badge>
                    <template v-if="lot.properties.length"><Badge v-for="property in lot.properties" :key="property" tone="success" size="sm" icon="ph:certificate">{{ t(`properties.${property}`) }}</Badge></template>
                    <Badge v-else tone="warning" size="sm" icon="ph:hourglass">{{ t('production.alkali.no_coa') }}</Badge>
                </li>
            </ul>
        </div>
    </Card>
</template>
