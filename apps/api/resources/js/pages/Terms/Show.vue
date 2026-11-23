<script setup>
/**
 * The terms of use and disclaimer (S-16). It is the gate every page redirects to until the current version is accepted, and it stays
 * readable afterwards (user menu). The wording is a project DRAFT until the owner or a lawyer supplies the final text (config terms.is_draft,
 * HUMAN-TODO); the draft notice says so. Accepting stores the version, the hash of the wording and the server time.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Button, Card, Checkbox, DescriptionList, FormField, PageHeader } from '@kit';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    document: { type: Object, required: true },
    acceptance: { type: Object, default: null },
    gated: { type: Boolean, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const form = useForm({ accepted: false, version: props.document.version });

const facts = computed(() => [
    { term: t('terms.version'), value: props.document.version, mono: true },
    { term: t('terms.hash'), value: props.document.hash, mono: true },
]);

const accept = () => form.post(props.urls.accept, { preserveScroll: true });
</script>

<template>
    <Head :title="t('terms.page_title')" />
    <div class="kit-page is-narrow" data-testid="terms-page" :data-accepted="acceptance !== null">
        <PageHeader :title="t('terms.title')" :description="gated ? t('terms.gate_notice') : t('terms.description')" />

        <div class="flex flex-col gap-4">
            <Alert v-if="document.draft" tone="warning" icon="ph:warning" :title="t('terms.draft_title')" data-testid="terms-draft">{{ t('terms.draft_description') }}</Alert>

            <Card>
                <DescriptionList :items="facts" :columns="1" />
                <p class="mt-2 text-label text-ink-3">{{ t('terms.hash_hint') }}</p>
            </Card>

            <Card data-testid="terms-sections">
                <div class="flex flex-col divide-y divide-line">
                    <section v-for="section in document.sections" :key="section.key" class="py-4 first:pt-0 last:pb-0" :data-section="section.key">
                        <h2 class="type-h5 mb-1 text-ink">{{ section.title }}</h2>
                        <p class="leading-relaxed text-ink-2">{{ section.body }}</p>
                    </section>
                </div>
            </Card>

            <Card>
                <div v-if="acceptance" class="flex flex-col items-start gap-3">
                    <Alert tone="success" icon="ph:seal-check" :title="t('terms.accepted_state')" class="w-full" data-testid="terms-accepted">{{ t('terms.accepted_on', { version: acceptance.version, date: when(acceptance.accepted_at) }) }}</Alert>
                    <Button variant="secondary" :href="urls.home" icon="ph:arrow-right">{{ t('terms.back') }}</Button>
                </div>
                <form v-else class="flex flex-col gap-4" novalidate data-testid="terms-form" @submit.prevent="accept">
                    <FormField :label="t('terms.agreement')" :error="form.errors.accepted ?? form.errors.version">
                        <Checkbox v-model="form.accepted" :label="t('terms.accept_label')" data-testid="terms-accept-box" />
                    </FormField>
                    <Button type="submit" class="self-start" icon="ph:check" :loading="form.processing" data-testid="terms-accept">{{ t('terms.accept') }}</Button>
                </form>
            </Card>
        </div>
    </div>
</template>
