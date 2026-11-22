<script setup>
/**
 * One template: its family, its verification status and its lines by role. A share is shown only where a source gave one ("not
 * provided" otherwise, never 0); the q.s. line says it balances to 100. "Use this template" opens the form that sends the filled
 * template through the engine's validation and the compliance check.
 */
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, PageHeader } from '@kit';
import VerificationBadge from '../../components/families/VerificationBadge.vue';
import UseTemplateModal from '../../components/templates/UseTemplateModal.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    template: { type: Object, required: true },
    tierOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const open = ref(false);

const facts = [
    { term: t('templates.show.family'), key: 'family' },
    { term: t('templates.show.regulator'), key: 'regulator' },
    { term: t('templates.show.status'), key: 'status' },
    ...(props.template.approved_by ? [{ term: t('templates.show.approval'), value: `${props.template.approved_by} · ${props.template.approved_on}` }] : []),
    { term: t('templates.show.confidence'), key: 'confidence' },
];
const columns = [
    { key: 'role', label: t('templates.show.column_role') },
    { key: 'percent', label: t('templates.show.column_share') },
    { key: 'kind', label: t('templates.show.column_kind') },
];
const kindOf = (line) => (line.qs ? 'qs' : line.optional ? 'optional' : 'required');
</script>

<template>
    <Head :title="template.name" />
    <div class="kit-page is-narrow">
        <PageHeader :title="template.name" :description="template.family.name">
            <template #actions>
                <Button variant="ghost" :href="urls.index" icon="ph:arrow-right">{{ t('templates.back') }}</Button>
                <Button icon="ph:flask" data-testid="use-template" @click="open = true">{{ t('templates.show.use') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Alert v-if="template.status === 'theoretical'" tone="warning" icon="ph:flask" data-testid="theoretical-alert">{{ t('templates.show.theoretical_alert') }}</Alert>

            <Card :title="t('templates.show.facts')">
                <DescriptionList :items="facts" :columns="2">
                    <template #family><a :href="template.family.href" class="text-primary-ink underline underline-offset-2">{{ template.family.name }}</a></template>
                    <template #regulator>
                        <Badge v-if="template.family.regulator" tone="info" mono>{{ template.family.regulator }}</Badge>
                        <span v-else class="text-ink-3">{{ t('families.not_stated') }}</span>
                    </template>
                    <template #status><VerificationBadge kind="template" :status="template.status" /></template>
                    <template #confidence><Badge tone="neutral" mono>{{ t(`enums.confidence.${template.confidence}`) }}</Badge></template>
                </DescriptionList>
            </Card>

            <Card :title="t('templates.show.lines_title')" :description="t('templates.show.lines_hint')">
                <DataTable :columns="columns" :rows="template.lines" row-key="key" :title="t('templates.show.lines_title')" data-testid="template-lines">
                    <template #cell-role="{ row }"><span class="text-ink">{{ t(`families.roles.${row.role}`) }}</span></template>
                    <template #cell-percent="{ row }">
                        <span v-if="row.qs" class="text-ink-2">{{ t('templates.show.share_qs') }}</span>
                        <bdi v-else-if="row.percent !== null" dir="ltr" class="tnum">{{ row.percent }}</bdi>
                        <span v-else class="text-ink-3">{{ t('templates.show.share_missing') }}</span>
                    </template>
                    <template #cell-kind="{ row }"><Badge :tone="row.qs ? 'info' : row.optional ? 'neutral' : 'warning'">{{ t(`templates.show.kind_${kindOf(row)}`) }}</Badge></template>
                </DataTable>
            </Card>
        </div>

        <UseTemplateModal v-model:open="open" :template="template" :tier-options="tierOptions" :urls="urls" />
    </div>
</template>
