<script setup>
/**
 * Picks a data source by searching, or creates one on the spot (SourceModal). Used by the property and price forms
 * and by the calculators' SourcedInput.
 *
 *   <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.source_id" required />
 *
 * `select` fires with the whole option ({ value, label, retrieved_at, ... }) when one is picked or created, so a caller can
 * keep the source's date and name. `bare` drops the FormField for a compact control (the label then names it for screen
 * readers, say which input in `label-params`); `selected-label` names a value set from outside on first paint.
 */
import { ref, watch } from 'vue';
import { Button } from '@kit';
import { useI18n } from '../../composables/useI18n';
import EntitySelect from '../form/EntitySelect.vue';
import SourceModal from './SourceModal.vue';

const props = defineProps({
    urls: { type: Object, required: true },
    kindOptions: { type: Array, required: true },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    bare: { type: Boolean, default: false },
    labelKey: { type: String, default: 'materials.fields.source' },
    labelParams: { type: Object, default: undefined },
    selectedLabel: { type: String, default: '' },
});

const model = defineModel({ type: String, default: null });
const emit = defineEmits(['select']);
const { t } = useI18n();
const creating = ref(false);
const label = ref(props.selectedLabel);
watch(() => props.selectedLabel, (value) => { label.value = value; });

function created(option) {
    label.value = option.label;
    model.value = option.value;
    emit('select', option);
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <EntitySelect
            v-model="model"
            :label-key="labelKey"
            :label-params="labelParams"
            :hint-key="bare ? null : 'materials.fields.source_hint'"
            :search-url="urls.sources"
            :selected-label="label"
            :error="error"
            :required="required"
            :bare="bare"
            @select="(option) => emit('select', option)"
        />
        <div>
            <Button variant="link" size="sm" icon="ph:plus" @click="creating = true">{{ t('materials.source.new') }}</Button>
        </div>
        <SourceModal v-model:open="creating" :url="urls.sourceStore" :kind-options="kindOptions" @created="created" />
    </div>
</template>
