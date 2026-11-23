<script setup>
/**
 * The one form for a trade material, in three uses:
 *   mode="create"  the library's "New material": saves and opens the material
 *   mode="edit"    the material's own page: saves in place
 *   mode="editor"  inside the formula editor (D-09): the material is always a draft, a price per kg is optional and
 *                  is stored as an unverified estimate (L7); answers with the option so the line can use it at once
 *
 *   <MaterialFormModal v-model:open="open" mode="create" :urls="urls" :status-options="statusOptions" />
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Button, FormField, Input, Modal, MultiSelect, Select, Switch, toast } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { useSuggestions } from '../../composables/useSuggestions';
import { normalizeDecimalText } from '../../lib/engine';
import { postJson } from '../../lib/http';
import SubstanceSelect from './SubstanceSelect.vue';

const props = defineProps({
    mode: { type: String, default: 'create' },
    material: { type: Object, default: null },
    urls: { type: Object, required: true },
    statusOptions: { type: Array, default: () => [] },
    /** The functional classes (M-02) to choose from; without them the field is not shown. */
    functionalClassOptions: { type: Array, default: () => [] },
    /** The ionic characters (M-08); without them the field is not shown. */
    ionicityOptions: { type: Array, default: () => [] },
});

const open = defineModel('open', { type: Boolean, default: false });
const emit = defineEmits(['created']);
const { t } = useI18n();
const { suggestions, load } = useSuggestions(() => props.urls.functions);

const initial = () => ({
    trade_name: props.material?.trade_name ?? '',
    grade: props.material?.grade ?? '',
    function: props.material?.function ?? '',
    functional_classes: props.material?.functional_classes ?? [],
    ionicity: props.material?.ionicity ?? null,
    substance_id: props.material?.substance?.id ?? null,
    is_mixture: props.material?.is_mixture ?? false,
    status: props.material?.status ?? 'draft',
    price_per_kg: '',
});

const form = useForm(initial());
// Select items cannot be empty, so "not stated" is a word of its own that is sent as null.
const NOT_STATED = 'not_stated';
const ionicityChoices = computed(() => [{ value: NOT_STATED, label: t('materials.fields.ionicity_placeholder') }, ...props.ionicityOptions]);
const ionicityModel = computed({
    get: () => form.ionicity ?? NOT_STATED,
    set: (value) => { form.ionicity = value === NOT_STATED ? null : value; },
});
const busy = ref(false);
const editor = computed(() => props.mode === 'editor');
const title = computed(() => t(`materials.form.title_${props.mode}`));
const submitLabel = computed(() => t(`materials.form.submit_${props.mode}`));

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(initial()).reset();
        form.clearErrors();
    }
});

// A mixture is not one substance.
watch(() => form.is_mixture, (mixture) => {
    if (mixture) {
        form.substance_id = null;
    }
});

function done() {
    open.value = false;
}

async function submit() {
    if (props.mode === 'create') {
        form.post(path('materials.store'), { preserveScroll: true, onSuccess: done });
    } else if (props.mode === 'edit') {
        form.put(path('materials.update', props.material.id), { preserveScroll: true, onSuccess: done });
    } else {
        await submitFromEditor();
    }
}

async function submitFromEditor() {
    busy.value = true;
    form.clearErrors();

    try {
        const { status: _status, ...data } = form.data();
        const result = await postJson(props.urls.quickMaterial, { ...data, price_per_kg: normalizeDecimalText(data.price_per_kg) });

        if (!result.ok) {
            form.setError(result.errors);

            return;
        }

        toast.success(t('materials.draft_created'));
        emit('created', result.data);
        done();
    } catch {
        toast.error(t('common.request_failed'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="title" :description="editor ? t('materials.form.description_editor') : t('materials.form.description')" size="md">
        <form id="material-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <Alert v-if="editor" tone="info" class="sm:col-span-2">{{ t('materials.form.editor_notice') }}</Alert>

            <FormField :label="t('materials.fields.trade_name')" :error="form.errors.trade_name" required class="sm:col-span-2">
                <Input v-model="form.trade_name" maxlength="255" />
            </FormField>
            <FormField :label="t('materials.fields.grade')" :hint="t('materials.fields.grade_hint')" :error="form.errors.grade">
                <Input v-model="form.grade" maxlength="255" />
            </FormField>
            <FormField :label="t('materials.fields.function')" :hint="t('materials.fields.function_hint')" :error="form.errors.function">
                <Input v-model="form.function" maxlength="255" list="material-functions" @focus="load" />
                <datalist id="material-functions"><option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" /></datalist>
            </FormField>

            <FormField v-if="functionalClassOptions.length" :label="t('materials.fields.functional_classes')" :hint="t('materials.fields.functional_classes_hint')" :error="form.errors.functional_classes" class="sm:col-span-2">
                <MultiSelect v-model="form.functional_classes" :options="functionalClassOptions" :placeholder="t('materials.fields.functional_classes_placeholder')" :aria-label="t('materials.fields.functional_classes')" data-testid="functional-classes" />
            </FormField>

            <FormField v-if="ionicityOptions.length" :label="t('materials.fields.ionicity')" :hint="t('materials.fields.ionicity_hint')" :error="form.errors.ionicity" class="sm:col-span-2">
                <Select v-model="ionicityModel" :options="ionicityChoices" data-testid="ionicity" />
            </FormField>

            <div class="sm:col-span-2">
                <SubstanceSelect v-model="form.substance_id" :urls="urls" :selected-label="material?.substance?.label ?? ''" :error="form.errors.substance_id" :disabled="form.is_mixture" />
            </div>

            <FormField :label="t('materials.fields.is_mixture')" :error="form.errors.is_mixture">
                <Switch v-model="form.is_mixture" :label="t('materials.fields.is_mixture_label')" />
            </FormField>
            <FormField v-if="!editor" :label="t('materials.fields.status')" :hint="t('materials.fields.status_hint')" :error="form.errors.status" required>
                <Select v-model="form.status" :options="statusOptions" />
            </FormField>
            <FormField v-else :label="t('materials.fields.price_per_kg')" :hint="t('materials.fields.price_per_kg_hint')" :error="form.errors.price_per_kg">
                <Input v-model="form.price_per_kg" ltr inputmode="decimal" :suffix="t('formulas.units.egp_per_kg')" />
            </FormField>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="material-form" :loading="form.processing || busy">{{ submitLabel }}</Button>
        </template>
    </Modal>
</template>
