<script setup>
/**
 * One product family: its regulator, IFRA category and tax regime (each shown as "not stated" while the sources give none), what
 * must be calculated (with a link to the calculator where one exists), what must be measured (marking what the lab loop cannot
 * record yet) and the templates that belong to it.
 */
import { Head } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DescriptionList, EmptyState, PageHeader } from '@kit';
import DisinfectionNotice from '../../components/compliance/DisinfectionNotice.vue';
import VerificationBadge from '../../components/families/VerificationBadge.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    family: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const facts = [
    { term: t('families.show.regulator'), key: 'regulator' },
    { term: t('families.show.ifra_category'), key: 'ifra' },
    { term: t('families.show.tax_regime'), key: 'tax' },
    { term: t('families.show.confidence'), key: 'confidence' },
    { term: t('families.show.verification'), key: 'verification' },
];
</script>

<template>
    <Head :title="family.name" />
    <div class="kit-page is-narrow">
        <PageHeader :title="family.name" :description="t(`families.groups.${family.group}`)">
            <template #actions>
                <Button variant="ghost" :href="urls.index" icon="ph:arrow-right">{{ t('families.back') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <DisinfectionNotice :family-group="family.group" />

            <Card :title="t('families.show.facts')">
                <DescriptionList :items="facts" :columns="2" data-testid="family-facts">
                    <template #regulator>
                        <span v-if="family.regulator" class="flex flex-wrap items-center gap-2">
                            <Badge tone="info" mono>{{ family.regulator }}</Badge>
                            <span class="text-ink-2">{{ t(`families.regulators.${family.regulator}`) }}</span>
                        </span>
                        <span v-else class="text-ink-3" :title="t('families.not_stated_reason')">{{ t('families.not_stated') }}</span>
                    </template>
                    <template #ifra>
                        <Badge v-if="family.ifra_category" tone="neutral" mono>{{ family.ifra_category }}</Badge>
                        <span v-else class="text-ink-3" :title="t('families.not_stated_reason')">{{ t('families.not_stated') }}</span>
                    </template>
                    <template #tax>
                        <span v-if="family.tax_regime">{{ family.tax_regime }}</span>
                        <span v-else class="text-ink-3" :title="t('families.not_stated_reason')">{{ t('families.not_stated') }}</span>
                    </template>
                    <template #confidence><Badge tone="neutral" mono>{{ t(`enums.confidence.${family.confidence}`) }}</Badge></template>
                    <template #verification><VerificationBadge kind="data" :status="family.verification" /></template>
                </DescriptionList>
                <Alert v-if="family.note" tone="info" icon="ph:info" class="mt-4">{{ t(`families.notes.${family.note}`) }}</Alert>
            </Card>

            <Card :title="t('families.show.calculations_title')" :description="t('families.show.calculations_hint')">
                <ul class="flex flex-col divide-y divide-line" data-testid="family-calculations">
                    <li v-for="calculation in family.calculations" :key="calculation.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="text-ink">{{ t(`families.calculations.${calculation.id}`) }}</span>
                        <span class="flex items-center gap-2">
                            <Badge :tone="calculation.status === 'available' ? 'success' : 'warning'">{{ t(`families.show.calculation_${calculation.status}`) }}</Badge>
                            <Button v-if="calculation.href" size="sm" variant="ghost" :href="calculation.href" icon="ph:calculator">{{ t('families.show.open_calculator') }}</Button>
                        </span>
                    </li>
                </ul>
            </Card>

            <Card :title="t('families.show.properties_title')" :description="t('families.show.properties_hint')">
                <ul v-if="family.properties.length" class="flex flex-col divide-y divide-line" data-testid="family-properties">
                    <li v-for="property in family.properties" :key="property.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="text-ink">{{ t(`families.measured.${property.id}`) }}</span>
                        <Badge :tone="property.lab_property ? 'success' : 'warning'">{{ property.lab_property ? t('families.show.property_recordable') : t('families.show.property_not_recordable') }}</Badge>
                    </li>
                </ul>
                <p v-else class="text-ink-3">{{ t('families.show.no_properties') }}</p>
            </Card>

            <Card :title="t('families.show.templates_title')">
                <ul v-if="family.templates.length" class="flex flex-col divide-y divide-line" data-testid="family-templates">
                    <li v-for="template in family.templates" :key="template.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <a :href="template.href" class="font-semibold text-primary-ink underline underline-offset-2">{{ template.name }}</a>
                        <VerificationBadge kind="template" :status="template.verification_status" />
                    </li>
                </ul>
                <EmptyState v-else compact icon="ph:files" :title="t('families.show.no_templates')" />
            </Card>
        </div>
    </div>
</template>
