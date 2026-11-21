<script setup>
/**
 * One test protocol template: what it measures, the instrument it needs, its unit and the numbered steps. A template
 * is guidance: it sets no acceptance limit (the limit row says so) and fixed settings come from the lab's own method.
 */
import { Head } from '@inertiajs/vue3';
import { path } from '../../../lib/routes';
import { Alert, Badge, Button, Card, DescriptionList, PageHeader } from '@kit';
import LimitText from '../../../components/lab/LimitText.vue';
import { useI18n } from '../../../composables/useI18n';

const props = defineProps({
    protocol: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const facts = [
    { term: t('lab.protocols_page.property'), key: 'property' },
    { term: t('lab.protocols_page.unit'), value: props.protocol.unit, mono: true },
    { term: t('lab.protocols_page.instrument'), value: props.protocol.instrument },
    { term: t('lab.protocols_page.limits'), key: 'limits' },
];
</script>

<template>
    <Head :title="protocol.name" />
    <div class="kit-page is-narrow">
        <PageHeader :title="protocol.name" :description="protocol.description">
            <template #actions>
                <Button variant="ghost" :href="urls.index" icon="ph:arrow-right">{{ t('lab.protocols_page.back') }}</Button>
                <Button :href="path('lab.trials.create')" icon="ph:test-tube">{{ t('lab.protocols_page.use') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Card :title="t('lab.protocols_page.facts')">
                <DescriptionList :items="facts" :columns="2">
                    <template #property><Badge tone="info">{{ t(`enums.lab_property.${protocol.property}`) }}</Badge></template>
                    <template #limits><LimitText :limits="protocol.limits" /></template>
                </DescriptionList>
            </Card>

            <Card :title="t('lab.protocols_page.steps_title')">
                <ol class="flex flex-col gap-3" data-testid="protocol-steps">
                    <li v-for="(step, index) in protocol.steps" :key="index" class="flex gap-3">
                        <Badge tone="neutral" mono>{{ index + 1 }}</Badge>
                        <p class="min-w-0 flex-1 text-ink">{{ step }}</p>
                    </li>
                </ol>
            </Card>

            <Alert tone="info" icon="ph:info">{{ t('lab.protocols_page.limits_note') }}</Alert>
        </div>
    </div>
</template>
