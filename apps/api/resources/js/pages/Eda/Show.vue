<script setup>
/**
 * One product's EDA notification file (N-08): the engine's checklist with a status per document (missing, attached, not applicable), the
 * document attached or the reason it does not apply, and the readiness of the whole file. When no source lists the documents the checklist is
 * empty and says so; the page never shows such a file as ready. A document is recorded in a modal: attached needs the document's title
 * (and may carry a scan), not applicable needs the reason, missing clears the record.
 */
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, EmptyState, FileUploader, FormField, Input, Modal, PageHeader, SegmentedControl, Textarea } from '@kit';
import StatusBadge from '../../components/StatusBadge.vue';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    product: { type: Object, required: true },
    readiness: { type: Object, required: true },
    items: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();

const editing = ref(null);
const files = ref([]);
const form = useForm({ status: 'attached', document_title: '', document_reference: '', note: '', file: null });
const statusOptions = computed(() => ['attached', 'not_applicable', 'missing'].map((value) => ({ value, label: t(`enums.eda_item_status.${value}`) })));
const open = computed({ get: () => editing.value !== null, set: (value) => { if (!value) { editing.value = null; } } });

const counts = computed(() => props.readiness.counts);
const applicable = computed(() => (counts.value ? counts.value.total - counts.value.notApplicable : 0));
const orphans = computed(() => props.readiness.orphans.join(t('common.list_separator')));

function begin(item) {
    form.reset();
    form.clearErrors();
    files.value = [];
    form.status = item.status === 'missing' ? 'attached' : item.status;
    form.document_title = item.document_title ?? '';
    form.document_reference = item.document_reference ?? '';
    form.note = item.note ?? '';
    editing.value = item;
}

function save() {
    form
        .transform((data) => ({ ...data, file: files.value[0]?.file ?? null }))
        .post(editing.value.url, { preserveScroll: true, forceFormData: true, onSuccess: () => { editing.value = null; } });
}

</script>

<template>
    <Head :title="t('eda.title')" />
    <div class="kit-page is-narrow" data-testid="eda-show" :data-state="readiness.state">
        <PageHeader :title="`${product.code} · ${product.name}`" :description="product.family ?? t('eda.show.no_family')">
            <template #actions>
                <Button variant="ghost" icon="ph:arrow-right" :href="urls.index">{{ t('eda.show.back') }}</Button>
                <Button variant="secondary" icon="ph:flask" :href="product.href">{{ t('eda.show.open_formula') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Card :title="t('eda.columns.readiness')" data-testid="eda-readiness">
                <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <StatusBadge kind="eda_readiness" :status="readiness.state" />
                        <span v-if="counts" class="text-label text-ink-2" data-testid="eda-progress">{{ t('eda.show.progress', { attached: counts.attached, applicable, not_applicable: counts.notApplicable }) }}</span>
                    </div>
                    <p v-if="readiness.reference" class="text-label text-ink-3">{{ t('eda.show.reference') }}: {{ readiness.reference.title }}</p>
                    <Alert v-if="readiness.state === 'out_of_scope'" tone="info" icon="ph:info" data-testid="eda-out-of-scope">{{ product.family ? t('eda.show.out_of_scope') : t('eda.show.no_family') }}</Alert>
                    <Alert v-if="readiness.state === 'items_not_sourced'" tone="warning" icon="ph:warning" :title="t('eda.not_sourced.title')" data-testid="eda-not-sourced">{{ t('eda.not_sourced.description') }}</Alert>
                    <Alert v-if="readiness.orphans.length" tone="warning" icon="ph:warning" data-testid="eda-orphans">{{ t('eda.show.orphans', { items: orphans }) }}</Alert>
                </div>
            </Card>

            <Card v-if="items.length" :title="t('eda.show.checklist')" data-testid="eda-checklist">
                <ul class="flex flex-col divide-y divide-line">
                    <li v-for="item in items" :key="item.code" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0" :data-item="item.code" :data-status="item.status">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="min-w-0 flex-1 font-semibold text-ink">{{ item.name }}</span>
                            <StatusBadge kind="eda_item_status" :status="item.status" />
                            <Button size="sm" variant="secondary" icon="ph:pencil-simple" data-testid="item-edit" @click="begin(item)">{{ t('eda.item.edit') }}</Button>
                        </div>
                        <p v-if="item.document_title" class="text-label text-ink-2">
                            {{ item.document_title }}
                            <Badge v-if="item.document_reference" tone="neutral" mono class="ms-2"><bdi dir="ltr">{{ item.document_reference }}</bdi></Badge>
                        </p>
                        <p v-if="item.note" class="text-label text-ink-3">{{ item.note }}</p>
                        <a v-if="item.file_url" :href="item.file_url" class="text-label text-primary-ink underline underline-offset-2" data-testid="item-file">{{ t('eda.item.download', { name: item.file_name }) }}</a>
                        <p v-if="item.updated_at" class="text-label text-ink-3">{{ t('eda.item.updated', { when: when(item.updated_at) }) }}</p>
                    </li>
                </ul>
            </Card>
            <EmptyState v-else-if="readiness.state === 'items_not_sourced'" compact icon="ph:list-checks" :title="t('eda.not_sourced.title')" :description="t('eda.not_sourced.description')" />
        </div>

        <Modal v-model:open="open" :title="editing?.name ?? ''" size="md">
            <form id="eda-item-form" class="flex flex-col gap-4" novalidate @submit.prevent="save">
                <FormField :label="t('eda.item.status_label')">
                    <SegmentedControl v-model="form.status" :options="statusOptions" data-testid="item-status" />
                </FormField>
                <template v-if="form.status === 'attached'">
                    <FormField :label="t('eda.item.document_title')" :hint="t('eda.item.document_title_hint')" :error="form.errors.document_title" required>
                        <Input v-model="form.document_title" maxlength="200" data-testid="item-title" />
                    </FormField>
                    <FormField :label="t('eda.item.reference')" :error="form.errors.document_reference">
                        <Input v-model="form.document_reference" ltr maxlength="100" data-testid="item-reference" />
                    </FormField>
                    <FormField :label="t('eda.item.file')" :hint="t('eda.item.file_hint')" :error="form.errors.file">
                        <FileUploader v-model="files" :auto-upload="false" layout="list" accept=".pdf,.jpg,.jpeg,.png" :max-files="1" data-testid="item-file-input" />
                    </FormField>
                </template>
                <FormField v-if="form.status === 'not_applicable'" :label="t('eda.item.note')" :hint="t('eda.item.note_hint')" :error="form.errors.note" required>
                    <Textarea v-model="form.note" :rows="3" maxlength="1000" data-testid="item-note" />
                </FormField>
                <Alert v-if="form.status === 'missing'" tone="info" icon="ph:info">{{ t('eda.item.clear') }}</Alert>
            </form>
            <template #footer>
                <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
                <Button type="submit" form="eda-item-form" :loading="form.processing" data-testid="item-save">{{ t('eda.item.save') }}</Button>
            </template>
        </Modal>
    </div>
</template>
