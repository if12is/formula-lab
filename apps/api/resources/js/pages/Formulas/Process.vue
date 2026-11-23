<script setup>
/**
 * The manufacturing steps of one formula version (Q-10): the order, the phase, the ingredient an `add` step puts in, the temperature,
 * mixing speed and time, and the check points with limits. They are part of the version and of its hash: editable while the version is a
 * draft, frozen once it is approved. A figure the formulator does not give stays empty (never 0); a limit needs its source and confidence,
 * otherwise it stays empty and the reading is "not evaluated".
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, EmptyState, FormField, Input, PageHeader, Select, Textarea } from '@kit';
import DecimalField from '../../components/form/DecimalField.vue';
import ProcessStepsList from '../../components/production/ProcessStepsList.vue';
import SourceSelect from '../../components/materials/SourceSelect.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    formula: { type: Object, required: true },
    version: { type: Object, required: true },
    lines: { type: Array, required: true },
    steps: { type: Array, required: true },
    choices: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();

const blankCheck = () => ({ property: props.choices.properties[0].value, unit: props.choices.units[props.choices.properties[0].value][0], limit_min: '', limit_max: '', source_id: null, source_label: '', confidence: '' });
const blankStep = () => ({ kind: 'mix', phase: '', line_no: '', temperature_c: '', mixing_speed_rpm: '', duration_minutes: '', note: '', checks: [] });
const text = (value) => value ?? '';

const form = useForm({
    steps: props.steps.map((step) => ({
        kind: step.kind,
        phase: text(step.phase),
        line_no: text(step.line_no),
        temperature_c: text(step.temperature_c),
        mixing_speed_rpm: text(step.mixing_speed_rpm),
        duration_minutes: text(step.duration_minutes),
        note: text(step.note),
        checks: step.checks.map((check) => ({ ...check, limit_min: text(check.limit_min), limit_max: text(check.limit_max), source_label: text(check.source_label), confidence: text(check.confidence) })),
    })),
});

const phaseOptions = computed(() => [{ value: '', label: t('production.process.no_phase') }, ...props.choices.phases]);
const lineOptions = computed(() => [{ value: '', label: t('production.process.no_line') }, ...props.lines]);
const unitOptions = (property) => props.choices.units[property].map((unit) => ({ value: unit, label: unit }));
const materials = computed(() => Object.fromEntries(props.lines.map((line) => [line.value, line.label.split(' — ')[1] ?? line.label])));
const sourceUrls = computed(() => ({ sources: props.urls.sources, sourceStore: props.urls.sourceStore }));

const move = (index, by) => {
    const target = index + by;
    if (target >= 0 && target < form.steps.length) {
        form.steps.splice(target, 0, form.steps.splice(index, 1)[0]);
    }
};
const errorOf = (key) => form.errors[key];

function changedProperty(check) {
    check.unit = props.choices.units[check.property][0];
}

function submit() {
    form.transform((data) => ({
        steps: data.steps.map((step) => ({
            ...step,
            checks: step.checks.map(({ source_label, ...check }) => check),
        })),
    })).put(props.urls.save, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('production.process.title', { formula: formula.code })" />
    <div class="kit-page">
        <PageHeader :title="t('production.process.title', { formula: formula.code })" :description="t('production.process.description')">
            <template #actions>
                <Badge tone="neutral" mono>{{ t('formulas.version_short', { version: version.number }) }}</Badge>
                <StatusBadge kind="formula_status" :status="version.status" />
                <Button variant="ghost" :href="urls.editor" icon="ph:arrow-right">{{ t('production.process.back') }}</Button>
                <Button v-if="version.status === 'approved'" variant="secondary" icon="ph:factory" :href="urls.newOrder" data-testid="process-new-order">{{ t('production.workflow.new_order') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Alert v-if="!version.editable" tone="info" icon="ph:lock-simple" :title="t('production.process.frozen_title')" data-testid="process-frozen">{{ t('production.process.frozen_description') }}</Alert>
            <p class="break-all text-label text-ink-3">{{ t('production.show.hash') }}: <bdi dir="ltr" class="font-mono" data-testid="process-hash">{{ version.hash }}</bdi></p>

            <template v-if="version.editable">
                <form class="flex flex-col gap-4" novalidate data-testid="process-form" @submit.prevent="submit">
                    <EmptyState v-if="!form.steps.length" variant="dashed" icon="ph:list-numbers" :title="t('production.process.empty_title')" :description="t('production.process.empty_description')" />

                    <Card v-for="(step, index) in form.steps" :key="index" :title="t('production.process.step_n', { number: index + 1 })" :data-testid="`step-${index + 1}`">
                        <template #actions>
                            <Button size="sm" variant="ghost" icon="ph:arrow-up" :aria-label="t('production.process.move_up', { number: index + 1 })" :disabled="index === 0" @click="move(index, -1)" />
                            <Button size="sm" variant="ghost" icon="ph:arrow-down" :aria-label="t('production.process.move_down', { number: index + 1 })" :disabled="index === form.steps.length - 1" @click="move(index, 1)" />
                            <Button size="sm" variant="ghost" icon="ph:trash" :aria-label="t('production.process.remove_step', { number: index + 1 })" @click="form.steps.splice(index, 1)" />
                        </template>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <FormField :label="t('production.process.kind')" :error="errorOf(`steps.${index}.kind`)" required>
                                <Select v-model="step.kind" :options="choices.kinds" :data-testid="`step-${index + 1}-kind`" />
                            </FormField>
                            <FormField :label="t('production.process.phase')" :error="errorOf(`steps.${index}.phase`)">
                                <Select v-model="step.phase" :options="phaseOptions" />
                            </FormField>
                            <FormField v-if="step.kind === 'add'" :label="t('production.process.line')" :error="errorOf(`steps.${index}.line_no`)" required>
                                <Select v-model="step.line_no" :options="lineOptions" :data-testid="`step-${index + 1}-line`" />
                            </FormField>
                            <DecimalField v-model="step.temperature_c" label-key="production.process.temperature" suffix="°C" :error="errorOf(`steps.${index}.temperature_c`)" />
                            <DecimalField v-model="step.mixing_speed_rpm" label-key="production.process.speed" :suffix="t('production.steps.rpm')" :error="errorOf(`steps.${index}.mixing_speed_rpm`)" />
                            <DecimalField v-model="step.duration_minutes" label-key="production.process.duration" :suffix="t('production.steps.minutes')" :error="errorOf(`steps.${index}.duration_minutes`)" />
                            <FormField :label="t('production.process.note')" :error="errorOf(`steps.${index}.note`)" class="sm:col-span-2 lg:col-span-3">
                                <Textarea v-model="step.note" :rows="2" maxlength="1000" />
                            </FormField>
                        </div>

                        <div class="mt-4 flex flex-col gap-3 border-t border-line pt-4">
                            <p class="font-semibold text-ink">{{ t('production.process.checks') }}</p>
                            <p class="text-label text-ink-3">{{ t('production.process.checks_hint') }}</p>
                            <div v-for="(check, checkIndex) in step.checks" :key="checkIndex" class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-2 lg:grid-cols-4" :data-testid="`check-${index + 1}-${checkIndex + 1}`">
                                <FormField :label="t('production.process.property')" :error="errorOf(`steps.${index}.checks.${checkIndex}.property`)" required>
                                    <Select v-model="check.property" :options="choices.properties" @update:model-value="changedProperty(check)" />
                                </FormField>
                                <FormField :label="t('production.process.unit')" :error="errorOf(`steps.${index}.checks.${checkIndex}.unit`)" required>
                                    <Select v-model="check.unit" :options="unitOptions(check.property)" />
                                </FormField>
                                <DecimalField v-model="check.limit_min" label-key="production.process.limit_min" :error="errorOf(`steps.${index}.checks.${checkIndex}.limit_min`)" />
                                <DecimalField v-model="check.limit_max" label-key="production.process.limit_max" :error="errorOf(`steps.${index}.checks.${checkIndex}.limit_max`)" />
                                <template v-if="check.limit_min !== '' || check.limit_max !== ''">
                                    <SourceSelect v-model="check.source_id" :urls="sourceUrls" :kind-options="choices.sourceKinds" :selected-label="check.source_label" :error="errorOf(`steps.${index}.checks.${checkIndex}.source_id`)" required class="sm:col-span-2" />
                                    <FormField :label="t('production.process.confidence')" :error="errorOf(`steps.${index}.checks.${checkIndex}.confidence`)" required>
                                        <Select v-model="check.confidence" :options="choices.confidences" />
                                    </FormField>
                                </template>
                                <div class="sm:col-span-2 lg:col-span-4"><Button size="sm" variant="ghost" icon="ph:trash" @click="step.checks.splice(checkIndex, 1)">{{ t('production.process.remove_check') }}</Button></div>
                            </div>
                            <div><Button size="sm" variant="secondary" icon="ph:plus" :data-testid="`add-check-${index + 1}`" @click="step.checks.push(blankCheck())">{{ t('production.process.add_check') }}</Button></div>
                        </div>
                    </Card>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="secondary" icon="ph:plus" data-testid="add-step" @click="form.steps.push(blankStep())">{{ t('production.process.add_step') }}</Button>
                        <Button type="submit" icon="ph:floppy-disk" :loading="form.processing" data-testid="process-save">{{ t('production.process.save') }}</Button>
                    </div>
                </form>
            </template>
            <Card v-else :title="t('production.process.steps_title')">
                <ProcessStepsList v-if="steps.length" :steps="steps" :materials="materials" />
                <EmptyState v-else variant="dashed" icon="ph:list-numbers" :title="t('production.process.empty_title')" />
            </Card>
        </div>
    </div>
</template>
