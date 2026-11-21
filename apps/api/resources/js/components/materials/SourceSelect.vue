<script setup>
/**
 * Picks a data source by searching, or creates one on the spot (SourceModal). Used by the property and price forms.
 *
 *   <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.source_id" required />
 */
import { ref } from 'vue';
import { Button } from '@kit';
import { useI18n } from '../../composables/useI18n';
import EntitySelect from '../form/EntitySelect.vue';
import SourceModal from './SourceModal.vue';

defineProps({
    urls: { type: Object, required: true },
    kindOptions: { type: Array, required: true },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
});

const model = defineModel({ type: String, default: null });
const { t } = useI18n();
const creating = ref(false);
const label = ref('');

function created(option) {
    label.value = option.label;
    model.value = option.value;
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <EntitySelect
            v-model="model"
            label-key="materials.fields.source"
            hint-key="materials.fields.source_hint"
            :search-url="urls.sources"
            :selected-label="label"
            :error="error"
            :required="required"
        />
        <div>
            <Button variant="link" size="sm" icon="ph:plus" @click="creating = true">{{ t('materials.source.new') }}</Button>
        </div>
        <SourceModal v-model:open="creating" :url="urls.sourceStore" :kind-options="kindOptions" @created="created" />
    </div>
</template>
