<script setup>
/**
 * Records what was really weighed (or counted) for one line of the batch, the raw-material lot it came from, who weighed it and, for a
 * person who sees prices, what the lot cost. A lot is how the COA values and the recall trace find this line (Q-03, Q-05).
 */
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Button, FormField, Input, Modal } from '@kit';
import DecimalField from '../form/DecimalField.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    line: { type: Object, default: null },
    url: { type: String, default: null },
    seesPrices: { type: Boolean, default: false },
    operator: { type: String, default: '' },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const form = useForm({ actual_quantity: '', lot_number: '', unit_cost: '', weighed_by: '' });

watch(() => props.line, (line) => {
    if (line) {
        form.defaults({ actual_quantity: line.actual ?? line.weigh, lot_number: line.lot ?? '', unit_cost: line.unit_cost ?? '', weighed_by: line.weighed_by ?? props.operator }).reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit() {
    form.put(props.url, { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('production.batches.weigh_title', { material: line?.material ?? '' })" :description="t('production.batches.weigh_description', { planned: line?.weigh ?? '', unit: line ? t(`production.units.${line.uom}`) : '' })">
        <form class="flex flex-col gap-4" novalidate data-testid="weigh-form" @submit.prevent="submit">
            <DecimalField v-model="form.actual_quantity" label-key="production.batches.actual_quantity" :suffix="line ? t(`production.units.${line.uom}`) : undefined" :error="form.errors.actual_quantity" required />
            <FormField :label="t('production.batches.lot_number')" :hint="t('production.batches.lot_hint')" :error="form.errors.lot_number">
                <Input v-model="form.lot_number" ltr maxlength="255" data-testid="weigh-lot" />
            </FormField>
            <DecimalField v-if="seesPrices" v-model="form.unit_cost" label-key="production.batches.unit_cost" hint-key="production.batches.unit_cost_hint" :suffix="line?.kind === 'packaging' ? t('production.units.per_unit') : t('production.units.per_kg')" :error="form.errors.unit_cost" />
            <FormField :label="t('production.batches.weighed_by')" :error="form.errors.weighed_by" required>
                <Input v-model="form.weighed_by" maxlength="255" data-testid="weigh-by" />
            </FormField>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" icon="ph:check" :loading="form.processing" data-testid="weigh-submit">{{ t('production.batches.save_weight') }}</Button>
                <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </Modal>
</template>
