<script setup>
/**
 * The fragrance-oil tab of a material (L-01): what the oil still lacks (supplier, certificate, a limit per category read from a
 * certificate, density, flash point, price) and its IFRA certificates with the limit of each category. A limit is a property value
 * that names its certificate; this panel only lists them and adds or removes the certificates. Nothing is filled in for a
 * missing item: it is shown as missing.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Badge, Button, Card, DataTable, EmptyState, IconButton, useConfirm } from '@kit';
import { useI18n } from '../../composables/useI18n';
import ConfidenceBadge from './ConfidenceBadge.vue';

const props = defineProps({
    materialId: { type: String, required: true },
    fragrance: { type: Object, required: true },
});

defineEmits(['add-certificate', 'add-limit']);
const { t } = useI18n();
const { confirm } = useConfirm();

const columns = computed(() => [
    { key: 'certificate_number', label: t('fragrance.columns.certificate_number') },
    { key: 'ifra_amendment', label: t('fragrance.columns.ifra_amendment'), hideBelow: 'md' },
    { key: 'issued_on', label: t('fragrance.columns.issued_on'), hideBelow: 'sm' },
    { key: 'source', label: t('fragrance.columns.source'), hideBelow: 'lg' },
    { key: 'confidence', label: t('fragrance.columns.confidence') },
    { key: 'limits', label: t('fragrance.columns.limits') },
    { key: 'actions', label: t('fragrance.columns.actions'), align: 'end' },
]);

async function remove(certificate) {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('fragrance.remove_title'),
        description: t('fragrance.remove_description', { certificate: certificate.certificate_number }),
        confirmLabel: t('materials.remove'),
    });

    if (confirmed) {
        router.delete(path('materials.ifra-certificates.destroy', [props.materialId, certificate.id]), { preserveScroll: true });
    }
}
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="fragrance-panel">
        <p class="max-w-prose text-label text-ink-3">{{ t('fragrance.intro') }}</p>

        <Card :title="t('fragrance.checks_title')">
            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" data-testid="fragrance-checks">
                <li v-for="check in fragrance.checks" :key="check.key" class="flex items-center justify-between gap-2" :data-check="check.key" :data-present="check.present">
                    <span>{{ t(`fragrance.checks.${check.key}`) }}</span>
                    <Badge :tone="check.present ? 'success' : 'warning'" :icon="check.present ? 'ph:check-circle' : 'ph:warning'">{{ check.present ? t('fragrance.present') : t('fragrance.missing') }}</Badge>
                </li>
            </ul>
            <p v-if="fragrance.supplier" class="mt-3 text-label text-ink-2">{{ t('fragrance.supplier_value', { name: fragrance.supplier }) }}</p>
            <Alert :tone="fragrance.complete ? 'success' : 'warning'" :icon="fragrance.complete ? 'ph:check-circle' : 'ph:warning'" class="mt-3">
                {{ fragrance.complete ? t('fragrance.complete') : t('fragrance.incomplete') }}
            </Alert>
        </Card>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-heading font-semibold">{{ t('fragrance.certificates_title') }}</h2>
            <Button icon="ph:plus" @click="$emit('add-certificate')">{{ t('fragrance.add_certificate') }}</Button>
        </div>

        <EmptyState v-if="fragrance.certificates.length === 0" variant="dashed" icon="ph:certificate" :title="t('fragrance.no_certificates_title')" :description="t('fragrance.no_certificates_description')">
            <template #actions><Button icon="ph:plus" @click="$emit('add-certificate')">{{ t('fragrance.add_certificate') }}</Button></template>
        </EmptyState>

        <DataTable v-else :columns="columns" :rows="fragrance.certificates" row-key="id" :mobile-columns="['certificate_number', 'confidence', 'limits']" :caption="t('fragrance.certificates_title')">
            <template #cell-certificate_number="{ row }"><bdi dir="ltr" class="font-mono text-label">{{ row.certificate_number }}</bdi></template>
            <template #cell-ifra_amendment="{ row }"><bdi v-if="row.ifra_amendment" dir="ltr">{{ row.ifra_amendment }}</bdi><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
            <template #cell-issued_on="{ row }"><bdi dir="ltr" class="tnum">{{ row.issued_on }}</bdi></template>
            <template #cell-source="{ row }">{{ row.source_title }}</template>
            <template #cell-confidence="{ row }"><ConfidenceBadge :confidence="row.confidence" /></template>
            <template #cell-limits="{ row }">
                <ul v-if="row.limits.length" class="flex flex-col gap-0.5 text-label">
                    <li v-for="limit in row.limits" :key="limit.id"><bdi dir="ltr">{{ limit.category }}</bdi>: <bdi dir="ltr" class="tnum font-semibold">{{ limit.value }} {{ limit.unit }}</bdi></li>
                </ul>
                <span v-else class="text-label text-ink-3">{{ t('fragrance.no_limits') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <span class="flex justify-end gap-1">
                    <IconButton icon="ph:plus" size="sm" variant="secondary" :label="t('fragrance.add_limit')" @click="$emit('add-limit', row.id)" />
                    <IconButton icon="ph:trash" size="sm" variant="danger-soft" :label="t('fragrance.remove_title')" @click="remove(row)" />
                </span>
            </template>
        </DataTable>
    </div>
</template>
