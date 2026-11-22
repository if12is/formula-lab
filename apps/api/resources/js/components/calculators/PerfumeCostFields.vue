<script setup>
/**
 * What the perfume cost needs on top of the recipe (L-10): the landed price per kg of each material, the loss of each process
 * stage (L-04, typed from your trials or filled from the lab trials of a formula version), the fill of one bottle and the glass
 * bottle, sprayer and box. Every figure carries its source; an empty one is reported missing by the engine, never read as zero.
 * The model is state.cost of lib/calculators/fragrance.js; its fields are edited in place.
 */
import { computed } from 'vue';
import { Alert, Card } from '@kit';
import EntitySelect from '../form/EntitySelect.vue';
import SourcedInput from '../form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { LOSS_STAGES, PACKAGING_SLOTS, PRICE_ITEMS, fillLossesFromLab } from '../../lib/calculators/fragrance';
import { path } from '../../lib/routes';

const props = defineProps({ urls: { type: Object, required: true } });
const cost = defineModel({ type: Object, required: true });
const { t } = useI18n();

const filled = computed(() => cost.value.lossFromLab);

// A formula version brings the losses its lab trials measured; a stage no trial measured stays as typed.
async function pickVersion(option) {
    cost.value.version = option;
    cost.value.lossFromLab = null;

    const response = await fetch(path('lab.formula-versions.process-losses', { version: option.value }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

    if (response.ok && cost.value.version?.value === option.value) {
        cost.value.lossFromLab = fillLossesFromLab(cost.value, (await response.json()).losses);
    }
}

function clearVersion(id) {
    if (id === null) {
        cost.value.version = null;
        cost.value.lossFromLab = null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <Card :title="t('calculators.fragrance.cost.prices_title')">
            <p class="mb-3 text-label text-ink-3">{{ t('calculators.fragrance.cost.prices_hint') }}</p>
            <div class="flex flex-col gap-4">
                <SourcedInput v-for="item in PRICE_ITEMS" :key="item" v-model="cost.prices[item]" :label-key="`calculators.fragrance.cost.price.${item}`" :units="['EGP']" unit-key="calculators.fragrance.cost.per_kg" />
            </div>
        </Card>

        <Card :title="t('calculators.fragrance.cost.loss_title')">
            <p class="mb-3 text-label text-ink-3">{{ t('calculators.fragrance.cost.loss_hint') }}</p>
            <div class="flex flex-col gap-4">
                <EntitySelect
                    :model-value="cost.version?.value ?? null"
                    label-key="calculators.fragrance.cost.version"
                    hint-key="calculators.fragrance.cost.version_hint"
                    placeholder-key="lab.form.version_placeholder"
                    :search-url="props.urls.formulaVersions"
                    :selected-label="cost.version?.label ?? ''"
                    @update:model-value="clearVersion"
                    @select="pickVersion"
                />
                <Alert v-if="cost.version && filled?.length" tone="success" icon="ph:flask" data-testid="lab-loss-used">{{ t('lab.process_loss.used') }}</Alert>
                <Alert v-else-if="cost.version && filled" tone="warning" icon="ph:hourglass" data-testid="lab-loss-none">{{ t('lab.process_loss.none') }}</Alert>
                <SourcedInput v-for="stage in LOSS_STAGES" :key="stage" v-model="cost.loss[stage]" :label-key="`calculators.fragrance.cost.loss.${stage}`" :units="['%w/w']" />
            </div>
        </Card>

        <Card :title="t('calculators.fragrance.cost.fill_title')">
            <SourcedInput v-model="cost.fill" label-key="calculators.fragrance.cost.fill" hint-key="calculators.fragrance.cost.fill_hint" :units="['mL', 'g']" />
        </Card>

        <Card :title="t('calculators.fragrance.cost.packaging_title')">
            <p class="mb-3 text-label text-ink-3">{{ t('calculators.fragrance.cost.packaging_hint') }}</p>
            <div class="flex flex-col gap-4">
                <template v-for="slot in PACKAGING_SLOTS" :key="slot">
                    <SourcedInput v-model="cost.packaging[slot].price" :label-key="`calculators.fragrance.cost.packaging.${slot}_price`" :units="['EGP']" unit-key="calculators.fragrance.cost.per_piece" />
                    <SourcedInput v-model="cost.packaging[slot].reject" :label-key="`calculators.fragrance.cost.packaging.${slot}_reject`" :units="['%w/w']" />
                </template>
            </div>
        </Card>
    </div>
</template>
