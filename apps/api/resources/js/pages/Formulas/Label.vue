<script setup>
/**
 * The label page of one formula version: its INCI list (engine buildInciList), listed by descending share, with the 1% line
 * marked (below it the label order is free, the list keeps its fixed order), the same INCI merged across materials, and the
 * label warnings of the chosen rule set. The browser only shows what the engine builds. A material with no composition
 * (or only part of one) makes the list impossible to build: the page lists exactly which materials to fill in, never a guess.
 * The Arabic detergent label (ES 6910, N-07) waits for the purchase of the standard and is not drawn here.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Badge, Button, Card, CopyButton, DescriptionList, EmptyState, FormField, PageHeader, Select } from '@kit';
import ExplainableNum from '../../components/explain/ExplainableNum.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { gapsByMaterial, inciList, reasonText, ruleSetsForProductType } from '../../lib/compliance';

const props = defineProps({
    formula: { type: Object, required: true },
    version: { type: Object, required: true },
    lines: { type: Array, required: true },
    materials: { type: Object, required: true },
    ruleSets: { type: Array, required: true },
});

const { t, has } = useI18n();

// The editor's line shape, so the engine bridge is the one the editor uses (a q.s. line is balanced by the engine).
const editorLines = computed(() => props.lines.map((line, index) => ({ key: `line-${index}`, material_id: line.trade_material_id, percent: line.percent_ww, is_qs: line.is_qs, phase: line.phase })));
const labelOf = (materialId) => props.materials[materialId]?.label ?? materialId;

const applicable = computed(() => ruleSetsForProductType(props.ruleSets, props.formula.product_type));
const ruleSetId = ref(applicable.value[0]?.id ?? 'none');
const ruleSetOptions = computed(() => [{ value: 'none', label: t('formulas.label.no_rule_set') }, ...props.ruleSets.map((ruleSet) => ({ value: ruleSet.id, label: `${ruleSet.name} · ${ruleSet.version}` }))]);
const ruleSet = computed(() => props.ruleSets.find((candidate) => candidate.id === ruleSetId.value) ?? null);

const result = computed(() => inciList(editorLines.value, props.materials, { ruleSet: ruleSet.value, productType: props.formula.product_type }));
const list = computed(() => (result.value.ok ? result.value.value : null));
const inciString = computed(() => (list.value ? list.value.items.map((item) => item.inci).join(', ') : ''));

const gaps = computed(() => (result.value.ok ? [] : gapsByMaterial(result.value.missing).map((gap) => ({
    ...gap,
    label: labelOf(gap.materialId),
    href: path('materials.show', { material: gap.materialId, tab: 'composition' }),
    texts: gap.reasons.map((reason) => reasonText(t, has, reason)),
}))));
const generalGaps = computed(() => (result.value.ok ? [] : [...new Set(result.value.missing.filter((gap) => !gap.materialId).map((gap) => reasonText(t, has, gap.reason)))]));

const entities = computed(() => Object.fromEntries(Object.entries(props.materials).map(([id, material]) => [id, { label: material.label, href: path('materials.show', { material: id, tab: 'composition' }) }])));
const sources = computed(() => ({}));

const warningText = (warning) => (has(`engine.${warning.key}`) ? t(`engine.${warning.key}`) : warning.key);

const facts = computed(() => [
    { term: t('formulas.fields.code'), value: props.formula.code, mono: true },
    { term: t('formulas.label.version'), key: 'version' },
    { term: t('formulas.fields.product_type'), value: props.formula.product_type },
]);

const print = () => window.print();
</script>

<template>
    <Head :title="t('formulas.label.page_title', { name: formula.name })" />
    <div class="kit-page">
        <PageHeader
            :title="t('formulas.label.title')"
            :description="formula.name"
            :back="path('formulas.versions.edit', [formula.id, version.number])"
            :back-label="formula.name"
        >
            <template #actions>
                <span class="flex flex-wrap gap-2 print:hidden">
                    <CopyButton v-if="list" :value="inciString" size="md">{{ t('formulas.label.copy') }}</CopyButton>
                    <Button variant="secondary" icon="ph:printer" data-testid="print-label" @click="print">{{ t('formulas.label.print') }}</Button>
                </span>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Card>
                <DescriptionList :items="facts" :columns="3">
                    <template #version>
                        <span class="flex flex-wrap items-center gap-2">
                            <Badge tone="neutral" mono>{{ t('formulas.version_short', { version: version.number }) }}</Badge>
                            <StatusBadge kind="formula_status" :status="version.status" />
                        </span>
                    </template>
                </DescriptionList>
            </Card>

            <Alert tone="info" icon="ph:hourglass" :title="t('formulas.label.es6910_title')" class="print:hidden" data-testid="es6910-pending">{{ t('formulas.label.es6910_description') }}</Alert>

            <FormField :label="t('formulas.label.rule_set')" :hint="t('formulas.label.rule_set_hint')" class="print:hidden">
                <Select v-model="ruleSetId" :options="ruleSetOptions" data-testid="label-rule-set" />
            </FormField>

            <Alert v-if="!result.ok" tone="warning" icon="ph:warning" :title="t('formulas.label.incomplete_title')" data-testid="inci-gaps">
                <p class="mb-2">{{ t('formulas.label.incomplete_description') }}</p>
                <ul class="flex flex-col gap-1">
                    <li v-for="text in generalGaps" :key="text">{{ text }}</li>
                    <li v-for="gap in gaps" :key="gap.materialId" class="flex flex-wrap items-baseline gap-x-2" :data-gap-material="gap.materialId">
                        <a :href="gap.href" class="font-semibold text-primary-ink underline underline-offset-2">{{ gap.label }}</a>
                        <span>{{ gap.texts.join(' ') }}</span>
                        <a :href="gap.href" class="text-label text-primary-ink underline underline-offset-2 print:hidden">{{ t('compliance.panel.fill_composition') }}</a>
                    </li>
                </ul>
            </Alert>

            <template v-else>
                <Card :title="t('formulas.label.inci_title')" :description="t('formulas.label.inci_description')" data-testid="inci-card">
                    <EmptyState v-if="list.items.length === 0" compact icon="ph:tag" :title="t('formulas.label.empty_title')" :description="t('formulas.label.empty_description')" />
                    <template v-else>
                        <p class="mb-4 rounded-lg border border-line bg-surface-2 p-3 text-sm leading-relaxed text-ink" dir="ltr" data-testid="inci-string">{{ inciString }}</p>

                        <ol class="flex flex-col" data-testid="inci-list">
                            <template v-for="(item, index) in list.items" :key="item.inci">
                                <li v-if="list.belowOnePctStartIndex === index" class="my-2 flex items-center gap-3 text-label text-ink-3" data-testid="one-percent-marker" role="separator">
                                    <span class="h-px flex-1 bg-line" />
                                    <Badge tone="info" icon="ph:arrow-down">{{ t('formulas.label.below_one_percent') }}</Badge>
                                    <span class="h-px flex-1 bg-line" />
                                </li>
                                <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-line py-2 last:border-b-0" :data-inci="item.inci" :data-below-one="item.belowOnePct">
                                    <bdi dir="ltr" class="tnum w-8 text-label text-ink-3">{{ index + 1 }}</bdi>
                                    <bdi dir="ltr" class="min-w-0 flex-1 font-semibold text-ink">{{ item.inci }}</bdi>
                                    <span class="flex items-baseline gap-1 text-ink-2"><ExplainableNum :traced="item.pct" :label="item.inci" :entities="entities" :sources="sources" /> <span class="text-label text-ink-3">%</span></span>
                                </li>
                            </template>
                        </ol>
                        <p v-if="list.belowOnePctStartIndex === null" class="mt-3 text-label text-ink-3">{{ t('formulas.label.all_above_one') }}</p>
                    </template>
                </Card>

                <Card :title="t('formulas.label.warnings_title')" :description="t('formulas.label.warnings_description')" data-testid="label-warnings">
                    <p v-if="!ruleSet" class="text-label text-ink-3">{{ t('formulas.label.warnings_pick') }}</p>
                    <p v-else-if="list.labelWarnings.length === 0" class="text-label text-ink-3">{{ t('formulas.label.warnings_none') }}</p>
                    <ul v-else class="flex flex-col divide-y divide-line">
                        <li v-for="warning in list.labelWarnings" :key="warning.ruleId" class="flex flex-wrap items-baseline gap-x-3 py-2" :data-label-warning="warning.ruleId">
                            <Badge tone="warning" icon="ph:warning">{{ t('formulas.label.warning') }}</Badge>
                            <span class="min-w-0 flex-1">{{ warningText(warning) }}</span>
                            <bdi dir="ltr" class="font-mono text-label text-ink-3">{{ warning.substance }}</bdi>
                        </li>
                    </ul>
                </Card>
            </template>
        </div>
    </div>
</template>
