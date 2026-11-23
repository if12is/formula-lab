<script setup>
/**
 * The review of one COA the model read (P-05): the original document beside every extracted value. Each value is shown with the line it was
 * read from, the model's reading confidence and what the deterministic checks found; the person edits it, then confirms or rejects it. Nothing
 * is saved until the whole extraction is confirmed, and only confirmed values become material properties (L1, the lot as reference).
 * Until then every figure is an unconfirmed reading (L7): the message says so, and no calculation can read it.
 */
import { computed, reactive, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Button, Card, DatePicker, DescriptionList, FormField, Input, Modal, PageHeader, Textarea } from '@kit';
import CoaFieldCard from '../../components/coa/CoaFieldCard.vue';
import CoaStatusBadge from '../../components/coa/CoaStatusBadge.vue';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';

const props = defineProps({
    extraction: { type: Object, required: true },
    fields: { type: Array, required: true },
    events: { type: Array, required: true },
    decided: { type: Object, default: null },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();

const open = computed(() => props.extraction.status === 'extracted');

const draftOf = (field) => ({
    decision: '',
    property: field.property_key ?? '',
    unit: field.unit ?? '',
    mode: field.min !== null || field.max !== null ? 'range' : 'value',
    value: field.value ?? '',
    min: field.min ?? '',
    max: field.max ?? '',
});
const drafts = reactive(Object.fromEntries(props.fields.map((field) => [field.id, draftOf(field)])));

const form = useForm({ lot_no: props.extraction.lot_no ?? '', valid_from: props.extraction.document_date, supplier: props.extraction.supplier ?? '', fields: [] });
const rejectForm = useForm({ reason: '' });
const rejecting = ref(false);

const counts = computed(() => {
    const decisions = Object.values(drafts).map((draft) => draft.decision);

    return { accept: decisions.filter((d) => d === 'accept').length, reject: decisions.filter((d) => d === 'reject').length, pending: decisions.filter((d) => d === '').length };
});
const ready = computed(() => counts.value.pending === 0 && counts.value.accept > 0 && form.lot_no.trim() !== '' && Boolean(form.valid_from));

function decimal(text) {
    const normalized = normalizeDecimalText(text);

    return normalized === '' ? null : normalized;
}

function confirm() {
    form
        .transform((data) => ({
            ...data,
            supplier: data.supplier.trim() === '' ? null : data.supplier.trim(),
            fields: props.fields.map((field) => {
                const draft = drafts[field.id];
                const accepted = draft.decision === 'accept';

                return {
                    id: field.id,
                    decision: draft.decision,
                    property: accepted ? draft.property : null,
                    unit: accepted ? draft.unit : null,
                    value: accepted && draft.mode === 'value' ? decimal(draft.value) : null,
                    min: accepted && draft.mode === 'range' ? decimal(draft.min) : null,
                    max: accepted && draft.mode === 'range' ? decimal(draft.max) : null,
                };
            }),
        }))
        .post(props.urls.confirm, { preserveScroll: true });
}

function reject() {
    rejectForm.post(props.urls.reject, { preserveScroll: true, onSuccess: () => { rejecting.value = false; } });
}

const details = computed(() => [
    { term: t('coa.review.material'), key: 'material' },
    { term: t('coa.review.supplier'), value: props.extraction.supplier ?? t('common.no_data') },
    { term: t('coa.review.lot_no'), value: props.decided?.lot_no ?? props.extraction.lot_no ?? t('common.no_data'), mono: true },
    { term: t('coa.review.document_date'), value: props.extraction.document_date ?? t('common.no_data'), mono: true },
    { term: t('coa.review.sha256'), value: props.extraction.file.sha256, mono: true },
]);
</script>

<template>
    <Head :title="t('coa.review.title')" />
    <div class="kit-page" data-testid="coa-review" :data-status="extraction.status">
        <PageHeader :title="extraction.material.name" :description="extraction.file.name">
            <template #actions>
                <CoaStatusBadge :status="extraction.status" />
                <Button variant="secondary" icon="ph:cube" :href="extraction.material.href">{{ t('coa.review.open_material') }}</Button>
                <Button variant="ghost" icon="ph:arrow-right" :href="urls.index">{{ t('coa.review.back') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Alert v-if="open" tone="warning" icon="ph:warning" :title="t('coa.review.unconfirmed_title')" data-testid="coa-unconfirmed">{{ t('coa.review.unconfirmed') }}</Alert>
            <Alert v-else-if="extraction.status === 'confirmed'" tone="success" icon="ph:seal-check" data-testid="coa-confirmed">{{ t('coa.review.confirmed_note') }}</Alert>
            <Alert v-else tone="info" icon="ph:prohibit" data-testid="coa-rejected">{{ t('coa.review.rejected_note') }}</Alert>
            <Alert v-if="extraction.text_check === 'unverified'" tone="info" icon="ph:eye" :title="t('coa.review.unverified_title')" data-testid="coa-unverified">{{ t('coa.review.unverified') }}</Alert>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
                    <Card :title="t('coa.review.original')" data-testid="coa-original">
                        <iframe v-if="extraction.file.kind === 'pdf'" :src="extraction.file.url" :title="extraction.file.name" class="h-[60dvh] w-full rounded-md border border-line bg-surface-2" data-testid="coa-original-pdf" />
                        <img v-else :src="extraction.file.url" :alt="extraction.file.name" class="max-h-[60dvh] w-full rounded-md border border-line object-contain" data-testid="coa-original-image" />
                        <a :href="extraction.file.url" target="_blank" rel="noopener" class="mt-3 inline-block text-label text-primary-ink underline underline-offset-2">{{ t('coa.review.open_original') }}</a>
                    </Card>
                    <Card :title="t('coa.review.document')">
                        <DescriptionList :items="details" :columns="1">
                            <template #material><a :href="extraction.material.href" class="text-primary-ink underline underline-offset-2">{{ extraction.material.name }}</a></template>
                        </DescriptionList>
                    </Card>
                    <Card :title="t('coa.review.history')" data-testid="coa-history">
                        <ul class="flex flex-col gap-2">
                            <li v-for="event in events" :key="event.occurred_at + event.status" class="flex flex-wrap items-center gap-2 text-label text-ink-2">
                                <CoaStatusBadge :status="event.status" />
                                <span>{{ event.user ?? t('coa.review.no_user') }}</span>
                                <bdi class="tnum text-ink-3">{{ when(event.occurred_at) }}</bdi>
                                <span v-if="event.reason" class="basis-full text-ink-3">{{ event.reason }}</span>
                            </li>
                        </ul>
                    </Card>
                </div>

                <div class="flex flex-col gap-4">
                    <Card v-if="open" :title="t('coa.review.header_title')">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField :label="t('coa.review.lot_no')" :hint="t('coa.review.lot_no_hint')" :error="form.errors.lot_no" required>
                                <Input v-model="form.lot_no" ltr maxlength="100" data-testid="coa-lot" />
                            </FormField>
                            <FormField :label="t('coa.review.valid_from')" :hint="t('coa.review.valid_from_hint')" :error="form.errors.valid_from" required>
                                <DatePicker v-model="form.valid_from" data-testid="coa-valid-from" />
                            </FormField>
                            <FormField :label="t('coa.review.supplier')" :error="form.errors.supplier" class="sm:col-span-2">
                                <Input v-model="form.supplier" maxlength="200" />
                            </FormField>
                        </div>
                    </Card>

                    <p v-if="fields.length === 0" class="text-body text-ink-2" data-testid="coa-no-fields">{{ t('coa.review.no_fields') }}</p>
                    <Alert v-if="form.errors.fields" tone="danger" icon="ph:warning">{{ form.errors.fields }}</Alert>
                    <CoaFieldCard
                        v-for="(field, index) in fields"
                        :key="field.id"
                        v-model="drafts[field.id]"
                        :field="field"
                        :index="index"
                        :readonly="!open"
                        :errors="form.errors"
                    />

                    <Card v-if="open" data-testid="coa-actions">
                        <div class="flex flex-wrap items-center gap-3">
                            <p class="basis-full text-label text-ink-2" data-testid="coa-counts">{{ t('coa.review.counts', counts) }}</p>
                            <Button variant="ghost" icon="ph:prohibit" data-testid="coa-reject-all" @click="rejecting = true">{{ t('coa.review.reject_all') }}</Button>
                            <Button icon="ph:seal-check" :disabled="!ready" :loading="form.processing" data-testid="coa-confirm" @click="confirm">{{ t('coa.review.confirm') }}</Button>
                        </div>
                        <p v-if="!ready" class="mt-2 text-label text-ink-3" data-testid="coa-confirm-hint">{{ t('coa.review.confirm_hint') }}</p>
                    </Card>
                </div>
            </div>
        </div>

        <Modal v-model:open="rejecting" :title="t('coa.review.reject_title')" :description="t('coa.review.reject_description')" size="md">
            <form id="coa-reject-form" novalidate @submit.prevent="reject">
                <FormField :label="t('coa.review.reason')" :hint="t('coa.review.reason_hint')" :error="rejectForm.errors.reason" required>
                    <Textarea v-model="rejectForm.reason" :rows="3" maxlength="1000" data-testid="coa-reject-reason" />
                </FormField>
            </form>
            <template #footer>
                <Button variant="ghost" @click="rejecting = false">{{ t('common.cancel') }}</Button>
                <Button type="submit" form="coa-reject-form" variant="danger" :loading="rejectForm.processing" data-testid="coa-reject-submit">{{ t('coa.review.reject_all') }}</Button>
            </template>
        </Modal>
    </div>
</template>
