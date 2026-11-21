<script setup>
/**
 * Soap-property indicators (hardness, cleansing, conditioning, bubbly, creamy) for the oils of the saponification calculator.
 * Which fatty acids count toward each is a published CONVENTION, so it is data with its own source and confidence: a person
 * selects the map to use as the source (none is chosen for them), then types each oil's fatty acid profile with its source.
 * The figures are the engine's (soapIndicators) and are shown as indicators only, never as measured properties.
 */
import { computed } from 'vue';
import { Alert, Badge, Card, FormField, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { useCalculatorLookups } from '../../composables/useCalculatorLookups';
import { runSoapProperties } from '../../lib/calculators/soap';
import ConfidenceBadge from '../materials/ConfidenceBadge.vue';
import SourceSelect from '../materials/SourceSelect.vue';
import DecimalField from '../form/DecimalField.vue';
import Num from '../Num.vue';

const props = defineProps({
    /** The maps the server offers: [{ value, label, description, sourceTitle, confidence, pendingVerification, notMapped, acids, map }]. */
    maps: { type: Array, required: true },
    /** The calculator state: `propertyMap` (the chosen map id) and `lines` (the oils, each with `acids` and `acidSource`). */
    state: { type: Object, required: true },
    /** Each oil's name as the engine and the results know it. */
    names: { type: Array, required: true },
    fallbackName: { type: Function, required: true },
});

const { t, has } = useI18n();
const lookups = useCalculatorLookups();

const NONE = 'none';
const options = computed(() => [{ value: NONE, label: t('calculators.soap_properties.none') }, ...props.maps.map((map) => ({ value: map.value, label: map.label }))]);
const selected = computed({
    get: () => props.state.propertyMap || NONE,
    set: (value) => { props.state.propertyMap = value === NONE ? '' : value; },
});
const chosen = computed(() => props.maps.find((map) => map.value === props.state.propertyMap) ?? null);
const outcome = computed(() => runSoapProperties(props.state, chosen.value?.map, props.fallbackName));

const propertyNames = computed(() => (chosen.value ? Object.keys(chosen.value.map.properties) : []));
const acidLabel = (acid) => t(`calculators.soap_properties.acids.${acid}`);
const propertyLabel = (name) => t(`calculators.soap_properties.properties.${name}`);
const termsOf = (name) => chosen.value.map.properties[name].terms.map((term) => acidLabel(term.acid)).join(' + ');
const notMappedText = computed(() => chosen.value?.notMapped.map((name) => t(`calculators.soap_properties.properties.${name}`)).join(' / ') ?? '');

function reasonText(gap) {
    const head = gap.reason.split(/[:( ]/)[0];
    const key = `calculators.soap_properties.gaps.${head}`;

    return has(key) ? t(key) : gap.reason;
}

function subjectText(gap) {
    const [head, detail] = gap.property.split(':');
    const subject = head === 'fattyAcid' && detail ? acidLabel(detail) : head;

    return [gap.materialId, subject].filter(Boolean).join(' · ');
}

function pickedSource(line, option) {
    line.acidSource.sourceId = option.value;
    line.acidSource.sourceTitle = option.label;
    line.acidSource.validFrom = option.retrieved_at ?? null;
}
</script>

<template>
    <Card :title="t('calculators.soap_properties.title')" :description="t('calculators.soap_properties.description')" data-testid="soap-properties">
        <div class="flex flex-col gap-4">
            <FormField :label="t('calculators.soap_properties.source_label')" :hint="t('calculators.soap_properties.source_hint')">
                <Select v-model="selected" :options="options" data-testid="soap-map-select" />
            </FormField>

            <template v-if="chosen">
                <div class="flex flex-col gap-2 rounded-lg border border-line p-3" data-testid="soap-map-card">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-ink">{{ chosen.label }}</span>
                        <ConfidenceBadge :confidence="chosen.confidence" />
                        <Badge v-if="chosen.pendingVerification" tone="warning" size="sm" icon="ph:hourglass" data-testid="soap-map-pending">{{ t('calculators.soap_properties.pending_badge') }}</Badge>
                    </div>
                    <p class="text-label text-ink-3">{{ chosen.description }}</p>
                    <p class="text-label text-ink-2">{{ t('calculators.soap_properties.source_is') }} <span class="font-semibold">{{ chosen.sourceTitle }}</span></p>
                    <ul class="flex flex-col gap-1 text-label text-ink-2" data-testid="soap-map-terms">
                        <li v-for="name in propertyNames" :key="name"><span class="font-semibold">{{ propertyLabel(name) }}</span> = {{ termsOf(name) }}</li>
                    </ul>
                    <Alert v-if="chosen.pendingVerification" tone="warning" icon="ph:warning">{{ t('calculators.soap_properties.pending_note') }}</Alert>
                    <p v-if="chosen.notMapped.length" class="text-label text-ink-3" data-testid="soap-not-mapped">{{ t('calculators.soap_properties.not_mapped', { names: notMappedText }) }}</p>
                </div>

                <div class="flex flex-col gap-3">
                    <h3 class="type-h6 text-ink">{{ t('calculators.soap_properties.profile_title') }}</h3>
                    <p class="text-label text-ink-3">{{ t('calculators.soap_properties.profile_hint') }}</p>
                    <div v-for="(line, index) in state.lines" :key="line.key" class="flex flex-col gap-3 rounded-lg border border-line p-3" :data-soap-profile="index">
                        <span class="font-semibold text-ink">{{ names[index] }}</span>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <DecimalField v-for="acid in chosen.acids" :key="acid" v-model="line.acids[acid]" label-key="calculators.soap_properties.acid_field" :label-params="{ acid: acidLabel(acid), n: index + 1 }" suffix="%" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                            <SourceSelect
                                v-model="line.acidSource.sourceId"
                                bare
                                label-key="calculators.soap_properties.profile_source_n"
                                :label-params="{ n: index + 1 }"
                                :selected-label="line.acidSource.sourceTitle"
                                :urls="lookups.urls"
                                :kind-options="lookups.sourceKindOptions"
                                @select="(option) => pickedSource(line, option)"
                            />
                            <FormField :label="t('calculators.soap_properties.profile_confidence_n', { n: index + 1 })">
                                <Select v-model="line.acidSource.confidence" :options="lookups.confidenceOptions" :placeholder="t('common.select_placeholder')" />
                            </FormField>
                        </div>
                    </div>
                </div>

                <div data-testid="soap-properties-results">
                    <p v-if="outcome.state === 'empty'" class="text-label text-ink-3">{{ t('calculators.soap_properties.empty') }}</p>
                    <Alert v-else-if="outcome.state === 'missing'" tone="warning" icon="ph:warning" :title="t('calculators.missing.title')" data-testid="soap-properties-missing">
                        <ul class="mt-1 flex flex-col gap-1">
                            <li v-for="gap in outcome.missing" :key="`${gap.materialId}-${gap.property}-${gap.reason}`">{{ subjectText(gap) }}: {{ reasonText(gap) }}</li>
                        </ul>
                    </Alert>
                    <div v-else class="flex flex-col gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="type-h6 text-ink">{{ t('calculators.soap_properties.results_title') }}</h3>
                            <Badge tone="info" size="sm">{{ t('calculators.indicator') }}</Badge>
                        </div>
                        <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <div v-for="(indicator, name) in outcome.indicators" :key="name" class="flex flex-col gap-1 rounded-lg border border-line p-3" :data-indicator="name">
                                <dt class="text-label text-ink-3">{{ propertyLabel(name) }}</dt>
                                <dd class="flex items-baseline gap-2 text-ink"><Num :value="indicator.value" :decimals="2" /> <ConfidenceBadge :confidence="indicator.confidence" /></dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </template>
        </div>
    </Card>
</template>
