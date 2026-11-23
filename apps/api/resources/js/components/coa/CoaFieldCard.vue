<script setup>
/**
 * One value the model read from a COA, beside what the document says: the name as printed, the quote, the page and the method, the
 * model's own reading confidence and what the checks found. While the extraction is open the person edits the property, value (or range)
 * and unit, and decides: confirm or reject. Once it is decided the card only shows what was decided. Every figure here is an UNCONFIRMED
 * reading (L7); it becomes L1 only when the extraction is confirmed.
 *
 *   <CoaFieldCard :field="field" v-model="drafts[field.id]" :readonly="status !== 'extracted'" :errors="errors" :index="i" />
 */
import { computed, watch } from 'vue';
import { Badge, Card, FormField, Input, SegmentedControl, Select } from '@kit';
import EntitySelect from '../form/EntitySelect.vue';
import ConfidenceBadge from '../materials/ConfidenceBadge.vue';
import PropertyValueText from '../materials/PropertyValueText.vue';
import { useI18n } from '../../composables/useI18n';
import { useProperties } from '../../composables/useProperties';

const props = defineProps({
    field: { type: Object, required: true },
    index: { type: Number, required: true },
    readonly: { type: Boolean, default: false },
    /** Validation errors of the whole form, keyed like "fields.0.value". */
    errors: { type: Object, default: () => ({}) },
});

/** { decision: '' | 'accept' | 'reject', property, unit, mode: 'value' | 'range', value, min, max } */
const draft = defineModel({ type: Object, required: true });

const { t } = useI18n();
const { label: propertyLabel, options: propertyOptions, unitsOf } = useProperties();

const units = computed(() => unitsOf(draft.value.property).map((unit) => ({ value: unit, label: unit })));
const modes = computed(() => [
    { value: 'value', label: t('materials.property.mode_value') },
    { value: 'range', label: t('materials.property.mode_range') },
]);
const decisions = computed(() => [
    { value: 'accept', label: t('coa.review.accept') },
    { value: 'reject', label: t('coa.review.reject') },
]);

const error = (key) => props.errors[`fields.${props.index}.${key}`] ?? null;
const blocking = computed(() => props.field.issues.filter((issue) => issue.blocking));
const notes = computed(() => props.field.issues.filter((issue) => !issue.blocking));
const READING_TONES = { high: 'success', medium: 'warning', low: 'danger' };

// The registry fixes which units a property may be stored in; a unit that is not one of them is dropped.
watch(() => draft.value.property, (property) => {
    if (!props.readonly && !unitsOf(property).includes(draft.value.unit)) {
        draft.value.unit = unitsOf(property)[0] ?? '';
    }
});

const finalDecision = computed(() => props.field.decision);
</script>

<template>
    <Card
        :data-testid="`coa-field-${index + 1}`"
        :data-decision="readonly ? (finalDecision?.decision ?? 'none') : draft.decision"
        :data-blocking="blocking.length > 0 ? 'true' : 'false'"
    >
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <p class="text-label text-ink-3">{{ t('coa.review.as_printed') }}</p>
                    <p class="font-semibold text-ink" data-testid="field-printed-name"><bdi>{{ field.property_as_printed ?? t('common.no_data') }}</bdi></p>
                </div>
                <Badge :tone="READING_TONES[field.reading_confidence]" :title="t('coa.review.reading_hint')">{{ t('coa.review.reading', { level: t(`coa.enums.reading_confidence.${field.reading_confidence}`) }) }}</Badge>
                <ConfidenceBadge :confidence="readonly && finalDecision?.decision === 'accept' ? 'L1_coa_batch' : 'L7_estimate'" />
            </div>

            <blockquote v-if="field.quote" class="rounded-md border-s-2 border-line bg-surface-2 px-3 py-2 text-label text-ink-2" data-testid="field-quote">
                <bdi>{{ field.quote }}</bdi>
                <span v-if="field.page" class="ms-2 text-ink-3">{{ t('coa.review.page', { page: field.page }) }}</span>
            </blockquote>
            <p v-if="field.method" class="text-label text-ink-2" data-testid="field-method">{{ t('coa.review.method') }}: <bdi dir="ltr">{{ field.method }}</bdi></p>

            <ul v-if="field.issues.length" class="flex flex-wrap gap-2" data-testid="field-issues">
                <li v-for="issue in blocking" :key="issue.code"><Badge tone="danger" icon="ph:warning" :title="t(`coa.issue_hint.${issue.code}`)">{{ t(`coa.enums.issue.${issue.code}`) }}</Badge></li>
                <li v-for="issue in notes" :key="issue.code"><Badge tone="warning" icon="ph:info" :title="t(`coa.issue_hint.${issue.code}`)">{{ t(`coa.enums.issue.${issue.code}`) }}</Badge></li>
            </ul>
            <p v-if="field.converted_from" class="text-label text-ink-3">{{ t('coa.review.converted_from', { unit: field.converted_from }) }}</p>

            <template v-if="readonly">
                <dl class="grid gap-2 text-body sm:grid-cols-2">
                    <div>
                        <dt class="text-label text-ink-3">{{ t('coa.review.property') }}</dt>
                        <dd>{{ finalDecision?.property ? propertyLabel(finalDecision.property) : (field.property_key ? propertyLabel(field.property_key) : t('common.no_data')) }}</dd>
                    </div>
                    <div>
                        <dt class="text-label text-ink-3">{{ t('coa.review.value') }}</dt>
                        <dd data-testid="field-final-value">
                            <PropertyValueText
                                v-if="finalDecision?.decision === 'accept'"
                                :value="finalDecision.value ?? null"
                                :min="finalDecision.min ?? null"
                                :max="finalDecision.max ?? null"
                                :unit="finalDecision.unit"
                            />
                            <PropertyValueText v-else :value="field.value" :min="field.min" :max="field.max" :unit="field.unit" />
                        </dd>
                    </div>
                </dl>
                <p data-testid="field-decision">
                    <Badge :tone="finalDecision?.decision === 'accept' ? 'success' : 'neutral'">{{ t(`coa.review.decided_${finalDecision?.decision ?? 'none'}`) }}</Badge>
                    <Badge v-if="finalDecision?.edited" tone="info" class="ms-2">{{ t('coa.review.edited') }}</Badge>
                </p>
            </template>

            <div v-else class="grid gap-4 sm:grid-cols-2">
                <EntitySelect
                    v-model="draft.property"
                    label-key="coa.review.property"
                    :options="propertyOptions"
                    :error="error('property')"
                    required
                />
                <FormField :label="t('coa.review.unit')" :error="error('unit')" required>
                    <Select v-model="draft.unit" :options="units" :placeholder="t('common.select_placeholder')" :disabled="units.length === 0" :data-testid="`field-unit-${index + 1}`" />
                </FormField>
                <FormField :label="t('materials.property.kind')" class="sm:col-span-2">
                    <SegmentedControl v-model="draft.mode" :options="modes" />
                </FormField>
                <FormField v-if="draft.mode === 'value'" :label="t('materials.property.value')" :error="error('value')" class="sm:col-span-2">
                    <Input v-model="draft.value" ltr inputmode="decimal" :data-testid="`field-value-${index + 1}`" />
                </FormField>
                <template v-else>
                    <FormField :label="t('materials.property.min')" :error="error('min')">
                        <Input v-model="draft.min" ltr inputmode="decimal" />
                    </FormField>
                    <FormField :label="t('materials.property.max')" :error="error('max')">
                        <Input v-model="draft.max" ltr inputmode="decimal" />
                    </FormField>
                </template>
                <FormField :label="t('coa.review.decision')" class="sm:col-span-2">
                    <SegmentedControl v-model="draft.decision" :options="decisions" :data-testid="`field-decision-${index + 1}`" />
                </FormField>
            </div>
        </div>
    </Card>
</template>
