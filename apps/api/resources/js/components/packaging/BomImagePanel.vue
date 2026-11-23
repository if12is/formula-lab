<script setup>
/**
 * The pack image of a SKU (S-12): shown from the private disk through the app, uploaded or replaced in a modal (type and size limits come
 * from the server's config; the server checks them again), and removed after a confirmation.
 *
 *   <BomImagePanel :bom="bom" :limits="limits" />
 */
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Alert, Button, Card, EmptyState, FileUploader, FormField, Modal, useConfirm } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { path } from '../../lib/routes';

const props = defineProps({
    bom: { type: Object, required: true },
    limits: { type: Object, required: true },
});

const { t } = useI18n();
const { confirm } = useConfirm();
const uploading = ref(false);
const files = ref([]);
const form = useForm({ image: null });

const maxMb = computed(() => Math.round((props.limits.imageMaxKb / 1024) * 10) / 10);
const accept = computed(() => props.limits.imageMimes.map((type) => `.${type}`).join(','));
const types = computed(() => props.limits.imageMimes.map((type) => type.toUpperCase()).join(', '));
const src = computed(() => `${path('packaging.boms.image.show', props.bom.id)}?v=${props.bom.image?.version ?? 0}`);

watch(uploading, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        files.value = [];
    }
});

function submit() {
    form
        .transform((data) => ({ ...data, image: files.value[0]?.file ?? null }))
        .post(path('packaging.boms.image.store', props.bom.id), { forceFormData: true, preserveScroll: true, onSuccess: () => { uploading.value = false; } });
}

async function remove() {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('packaging.bom.image.remove_title'),
        description: t('packaging.bom.image.remove_description'),
        confirmLabel: t('packaging.bom.image.remove'),
    });

    if (confirmed) {
        router.delete(path('packaging.boms.image.destroy', props.bom.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Card :title="t('packaging.bom.image.title')" :description="t('packaging.bom.image.description', { types, size: maxMb })" data-testid="bom-image-card">
        <div v-if="bom.image" class="flex flex-col gap-3">
            <img :src="src" :alt="t('packaging.bom.image.alt', { name: bom.name })" class="max-h-72 w-full rounded-lg border border-line bg-surface-2 object-contain" data-testid="bom-image" />
            <div class="flex flex-wrap gap-2">
                <Button variant="secondary" icon="ph:upload-simple" data-testid="bom-image-replace" @click="uploading = true">{{ t('packaging.bom.image.replace') }}</Button>
                <Button variant="danger-soft" icon="ph:trash" data-testid="bom-image-remove" @click="remove">{{ t('packaging.bom.image.remove') }}</Button>
            </div>
        </div>
        <EmptyState v-else compact icon="ph:image" :title="t('packaging.bom.image.none')">
            <template #actions>
                <Button icon="ph:upload-simple" data-testid="bom-image-upload" @click="uploading = true">{{ t('packaging.bom.image.upload') }}</Button>
            </template>
        </EmptyState>

        <Modal v-model:open="uploading" :title="t('packaging.bom.image.title')" :description="t('packaging.bom.image.description', { types, size: maxMb })" size="md">
            <form id="bom-image-form" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
                <FormField :label="t('packaging.bom.image.file_label')" :error="form.errors.image" required>
                    <FileUploader v-model="files" :auto-upload="false" layout="list" :accept="accept" :max-files="1" :max-size="maxMb" data-testid="bom-image-input" />
                </FormField>
                <Alert v-if="form.errors.image" tone="danger">{{ form.errors.image }}</Alert>
            </form>
            <template #footer>
                <Button variant="ghost" @click="uploading = false">{{ t('common.cancel') }}</Button>
                <Button type="submit" form="bom-image-form" icon="ph:upload-simple" :loading="form.processing" :disabled="files.length === 0" data-testid="bom-image-submit">{{ t('packaging.bom.image.upload') }}</Button>
            </template>
        </Modal>
    </Card>
</template>
