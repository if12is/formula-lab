<script setup>
/**
 * Uploads a COA (PDF or image) for the model to read. The material it belongs to is searched; the file's type and size limits come from the
 * server's config. Reading takes a few seconds: the button shows it is working, and the review screen opens when the reading is done.
 */
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, Button, FileUploader, FormField, Modal } from '@kit';
import AssistantConsentPrompt from '../assistant/AssistantConsentPrompt.vue';
import EntitySelect from '../form/EntitySelect.vue';
import { useAssistantConsent } from '../../composables/useAssistantConsent';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    upload: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const consent = useAssistantConsent();
// The certificate is sent to the Claude service, so reading it needs the session's consent for supplier certificates (P-10).
const CERTIFICATE = 'supplier_certificate';

const files = ref([]);
const form = useForm({ trade_material_id: null, file: null });

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        files.value = [];
    }
});

function submit() {
    form
        .transform((data) => ({ ...data, file: files.value[0]?.file ?? null }))
        .post(props.upload.url, { forceFormData: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('coa.upload.title')" :description="t('coa.upload.description')" size="md">
        <form id="coa-upload-form" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <EntitySelect
                v-model="form.trade_material_id"
                label-key="coa.upload.material"
                hint-key="coa.upload.material_hint"
                :search-url="upload.materialSearchUrl"
                :error="form.errors.trade_material_id"
                required
            />
            <FormField :label="t('coa.upload.file')" :hint="t('coa.upload.file_hint', { max: upload.maxMb })" :error="form.errors.file" required>
                <FileUploader v-model="files" :auto-upload="false" layout="list" :accept="upload.accept" :max-files="1" :max-size="upload.maxMb" data-testid="coa-file-input" />
            </FormField>
            <Alert tone="info" icon="ph:info">{{ t('coa.upload.notice') }}</Alert>
            <AssistantConsentPrompt :only="[CERTIFICATE]" reason-key="assistant_ui.consent.coa_needed" />
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="coa-upload-form" icon="ph:magic-wand" :loading="form.processing" :disabled="!form.trade_material_id || files.length === 0 || !consent.allows([CERTIFICATE])" data-testid="coa-upload-submit">
                {{ form.processing ? t('coa.upload.reading') : t('coa.upload.submit') }}
            </Button>
        </template>
    </Modal>
</template>
