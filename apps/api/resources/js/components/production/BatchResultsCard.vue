<script setup>
/**
 * The figures only the end of a batch knows (Q-04, Q-09): the waste planned and the waste recorded, the units made and the conversion cost
 * really spent. The waste is compared with the plan by the engine, as a difference and a verdict at the configured threshold; the stock
 * effect of waste is Sanad's. A person who does not see prices is not asked for the conversion cost.
 */
import { useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, FormField, Textarea } from '@kit';
import DecimalField from '../form/DecimalField.vue';
import Num from '../Num.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    batch: { type: Object, required: true },
    waste: { type: Object, required: true },
    seesPrices: { type: Boolean, default: false },
    url: { type: String, required: true },
});

const { t } = useI18n();
const form = useForm({
    planned_waste_pct: props.batch.planned_waste_pct ?? '',
    actual_waste_g: props.batch.actual_waste_g ?? '',
    actual_units: props.batch.actual_units ?? '',
    actual_conversion_egp: props.batch.actual_conversion_egp ?? '',
    conversion_note: props.batch.conversion_note ?? '',
});

const TONES = { none: 'success', within: 'success', exceeds: 'danger', threshold_not_set: 'warning' };

function submit() {
    form.put(props.url, { preserveScroll: true });
}
</script>

<template>
    <Card :title="t('production.results.title')" :description="t('production.results.description')" data-testid="results-card">
        <form v-if="batch.open" class="grid gap-4 sm:grid-cols-2" novalidate data-testid="results-form" @submit.prevent="submit">
            <DecimalField v-model="form.planned_waste_pct" label-key="production.results.planned_waste" suffix="%" :error="form.errors.planned_waste_pct" />
            <DecimalField v-model="form.actual_waste_g" label-key="production.results.actual_waste" :suffix="t('production.units.g')" :error="form.errors.actual_waste_g" />
            <DecimalField v-model="form.actual_units" label-key="production.results.actual_units" :error="form.errors.actual_units" />
            <DecimalField v-if="seesPrices" v-model="form.actual_conversion_egp" label-key="production.results.conversion" :suffix="t('production.units.egp')" :error="form.errors.actual_conversion_egp" />
            <FormField v-if="seesPrices" :label="t('production.results.conversion_note')" :hint="t('production.results.conversion_note_hint')" :error="form.errors.conversion_note" class="sm:col-span-2">
                <Textarea v-model="form.conversion_note" :rows="2" maxlength="1000" />
            </FormField>
            <div class="sm:col-span-2"><Button type="submit" icon="ph:floppy-disk" :loading="form.processing" data-testid="results-submit">{{ t('production.results.save') }}</Button></div>
        </form>

        <div class="mt-4 flex flex-col gap-2" data-testid="waste-comparison" :data-state="waste.state">
            <p v-if="waste.state === 'not_recorded'" class="text-label text-ink-3">{{ t('production.results.waste_not_recorded') }}</p>
            <Alert v-else-if="waste.state === 'incomplete'" tone="warning" icon="ph:warning">
                <ul class="list-disc ps-5"><li v-for="reason in waste.missing" :key="reason">{{ reason }}</li></ul>
            </Alert>
            <template v-else>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <span>{{ t('production.results.planned_waste_g') }}: <Num :value="waste.result.plannedWasteG.value"><template #suffix> {{ t('production.units.g') }}</template></Num></span>
                    <span>{{ t('production.results.actual_waste') }}: <Num :value="waste.result.deviation.actual"><template #suffix> {{ t('production.units.g') }}</template></Num></span>
                    <span>{{ t('production.results.difference') }}: <Num :value="waste.result.deviation.difference.value"><template #suffix> {{ t('production.units.g') }}</template></Num> (<Num :value="waste.result.deviation.differencePctDisplay"><template #suffix> %</template></Num>)</span>
                    <Badge :tone="TONES[waste.result.deviation.verdict]" dot data-testid="waste-verdict">{{ t(`enums.deviation_verdict.${waste.result.deviation.verdict}`) }}</Badge>
                </div>
            </template>
        </div>
    </Card>
</template>
