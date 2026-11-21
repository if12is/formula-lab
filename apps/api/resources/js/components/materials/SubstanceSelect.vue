<script setup>
/**
 * Picks a substance by searching its INCI, CAS or name, or creates one on the spot (SubstanceModal).
 * `selected-label` names the current value on first paint (an edit form).
 *
 *   <SubstanceSelect v-model="form.substance_id" :urls="urls" :selected-label="material.substance?.label" :error="form.errors.substance_id" />
 */
import { ref, watch } from 'vue';
import { Button } from '@kit';
import { useI18n } from '../../composables/useI18n';
import EntitySelect from '../form/EntitySelect.vue';
import SubstanceModal from './SubstanceModal.vue';

const props = defineProps({
    urls: { type: Object, required: true },
    selectedLabel: { type: String, default: '' },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const model = defineModel({ type: String, default: null });
const { t } = useI18n();
const creating = ref(false);
const label = ref(props.selectedLabel);

watch(() => props.selectedLabel, (value) => { label.value = value; });

function created(option) {
    label.value = option.label;
    model.value = option.value;
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <EntitySelect
            v-model="model"
            label-key="materials.fields.substance"
            hint-key="materials.fields.substance_hint"
            :search-url="urls.substances"
            :selected-label="label"
            :error="error"
            :disabled="disabled"
        />
        <div>
            <Button variant="link" size="sm" icon="ph:plus" :disabled="disabled" @click="creating = true">{{ t('materials.substance.new') }}</Button>
        </div>
        <SubstanceModal v-model:open="creating" :url="urls.substanceStore" @created="created" />
    </div>
</template>
