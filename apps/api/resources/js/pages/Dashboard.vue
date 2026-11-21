<script setup>
/**
 * Template: Dashboard home (taif-kit demo/pages/Overview.vue) cut down to the first-run state.
 * The KPI cards show "—" with the reason until real figures exist (S-15); never 0.
 */
import { Head } from '@inertiajs/vue3';
import { Card, EmptyState, PageHeader, StatCard } from '@kit';
import { useI18n } from '../composables/useI18n';

const { t } = useI18n();

const kpis = [
    { id: 'formulas', labelKey: 'dashboard.kpi.formulas', icon: 'ph:flask' },
    { id: 'avg_cost', labelKey: 'dashboard.kpi.avg_cost', icon: 'ph:coins' },
    { id: 'compliance', labelKey: 'dashboard.kpi.compliance', icon: 'ph:seal-check' },
    { id: 'lab', labelKey: 'dashboard.kpi.lab', icon: 'ph:test-tube' },
];
</script>

<template>
    <Head :title="t('dashboard.title')" />
    <div class="kit-page">
        <PageHeader :title="t('dashboard.title')" :description="t('dashboard.description')" />

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :aria-label="t('dashboard.title')">
            <StatCard
                v-for="kpi in kpis"
                :key="kpi.id"
                :label="t(kpi.labelKey)"
                :value="null"
                :reason="t('dashboard.no_data_reason')"
                :icon="kpi.icon"
            />
        </section>

        <Card class="mt-6">
            <EmptyState icon="ph:flask" :title="t('dashboard.empty_title')" :description="t('dashboard.empty_description')" />
        </Card>
    </div>
</template>
