<script setup>
/**
 * The formula editor, for a new formula, a draft being edited, and an approved or older version shown read-only.
 *
 * Lines (material, % w/w, phase) sit under a pinned totals bar that always shows what the engine says: the sum and its
 * deviation from 100 (draftSummary), the material cost per kg (calculate) and the q.s. balancing (balanceQs). The browser
 * computes nothing itself. Saving never edits a version: it writes the next one, with a required reason.
 *
 * A material typed in from here (D-09) is a draft; a price typed with it is an unverified estimate (L7), and the engine
 * refuses to cost with it, so the cost reads "not reliable" until a dated, sourced price is added.
 */
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Badge, Button, Card, DescriptionList, FieldError, FormField, IconButton, Input, PageHeader, RadioGroup, Select, StickyBar, Textarea, Tooltip } from '@kit';
import ExplainableNum from '../../components/explain/ExplainableNum.vue';
import EntitySelect from '../../components/form/EntitySelect.vue';
import DisplayCurrencyLine from '../../components/currency/DisplayCurrencyLine.vue';
import CompliancePanel from '../../components/compliance/CompliancePanel.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import MaterialFormModal from '../../components/materials/MaterialFormModal.vue';
import Num from '../../components/Num.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useFormulaDraft } from '../../composables/useFormulaDraft';
import { useI18n } from '../../composables/useI18n';
import { useSuggestions } from '../../composables/useSuggestions';
import { isDecimalString, normalizeDecimalText, sumTraced } from '../../lib/engine';

const props = defineProps({
    formula: { type: Object, default: null },
    version: { type: Object, default: null },
    lines: { type: Array, required: true },
    materials: { type: Object, required: true },
    history: { type: Array, required: true },
    /** { ruleSets } for the compliance panel; only an existing formula has one (its product type and IFRA category are saved). */
    compliance: { type: Object, default: null },
    datasetVersion: { type: String, required: true },
    tierOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const creating = computed(() => !props.formula);
const draft = useFormulaDraft({ lines: props.lines, materials: props.materials, datasetVersion: props.datasetVersion });
const { suggestions: productTypes, load: loadProductTypes } = useSuggestions(() => props.urls.productTypes);

// A version that is not the editable latest draft is read-only until "new version" is chosen.
const startedNewVersion = ref(false);
const editing = computed(() => creating.value || props.version.editable || startedNewVersion.value);
const approved = computed(() => props.version?.status === 'approved');

const form = useForm({
    code: '',
    name: '',
    product_type: '',
    tier: 'none',
    reason: '',
});
// Saving redirects to the next version in this same page: start over from what the server sent.
watch(() => [props.formula?.id, props.version?.number], () => {
    draft.reset(props.lines, props.materials);
    startedNewVersion.value = false;
    form.reset();
    form.clearErrors();
    tried.value = false;
    balanceErrors.value = [];
});

const materialModal = ref(false);
const balanceErrors = ref([]);
const tried = ref(false);

const tierOptions = computed(() => [{ value: 'none', label: t('formulas.tier_none') }, ...props.tierOptions]);
const qsOptions = computed(() => draft.lines.value
    .filter((line) => line.material_id)
    .map((line) => ({ value: line.key, label: draft.labelOf(line.material_id) })));

const historyHref = computed(() => (props.formula ? path('formulas.compare', props.formula.id) : null));

const facts = computed(() => (props.formula
    ? [
        { term: t('formulas.fields.code'), value: props.formula.code, mono: true },
        { term: t('formulas.fields.name'), value: props.formula.name },
        { term: t('formulas.fields.product_type'), value: props.formula.product_type },
        { term: t('formulas.fields.tier'), value: props.formula.tier ? t(`enums.product_tier.${props.formula.tier}`) : t('common.no_data') },
    ]
    : []));

/* ---- what the engine says ---------------------------------------------- */

const issueText = (issue) => t(`engine.${issue.messageKey}`);
// A percentage nobody has typed yet is not an error until a save was tried.
const lineProblems = (line) => (draft.summary.value.issues[line.key] ?? []).filter((issue) => tried.value || line.percent !== '' || issue.code !== 'INVALID_PERCENT');
const fieldError = (index, field) => form.errors[`lines.${index}.${field}`] ?? null;
const lineError = (line, index) => fieldError(index, 'trade_material_id')
    ?? fieldError(index, 'percent_ww')
    ?? (tried.value && !line.material_id ? t('formulas.errors.material_required') : null)
    ?? (lineProblems(line)[0] ? issueText(lineProblems(line)[0]) : null);

const cost = computed(() => draft.cost.value);

/* What the explain panel can name: each material by its name (the engine labels it by id), each price by its source. */
const entities = computed(() => Object.fromEntries(Object.entries(draft.materials).map(([id, material]) => [id, { label: material.label, href: path('materials.show', { material: id }) }])));
const sources = computed(() => Object.fromEntries(Object.entries(draft.materials).flatMap(([id, material]) => (material.prices ?? []).flatMap((price) => [
    [price.source_id, { title: price.source_title ?? null, href: path('materials.show', { material: id, tab: 'prices' }) }],
    // The exchange rate behind a foreign price: its source opens the exchange rates screen.
    ...(price.fx ? [[price.fx.source_id, { title: price.fx.source_title, href: path('settings.exchange-rates.index') }]] : []),
]))));
const sumTrace = computed(() => sumTraced(draft.lines.value, draft.labelOf, draft.summary.value.sum));
const formulaGaps = computed(() => cost.value.missing.filter((gap) => gap.property === 'formula'));
const priceGaps = computed(() => {
    const byMaterial = new Map();

    for (const gap of cost.value.missing.filter((entry) => entry.materialId && entry.property !== 'formula')) {
        byMaterial.set(gap.materialId, [...(byMaterial.get(gap.materialId) ?? []), gap]);
    }

    return [...byMaterial].map(([materialId, gaps]) => {
        // A foreign price without a rate for its date: the way out is an exchange rate, not another price.
        const fxGap = gaps.map((gap) => gap.property.match(/^fx:([A-Z]{3})->EGP$/)).find(Boolean);
        const priced = cost.value.prices[materialId];

        return {
            materialId,
            label: draft.labelOf(materialId),
            reasons: [...new Set(gaps.map((gap) => gap.reason))],
            href: path('materials.show', { material: materialId, tab: 'prices' }),
            fx: fxGap ? { currency: fxGap[1], date: priced?.validFrom ?? null, href: path('settings.exchange-rates.index', { new_currency: fxGap[1], ...(priced ? { new_date: priced.validFrom } : {}) }) } : null,
        };
    });
});
const reasonsText = (reasons) => reasons.map((reason) => t(`engine.${reason}`)).join(' ');
const estimateGap = computed(() => cost.value.missing.some((gap) => gap.reason === 'confidence.l7_requires_signed_override'));
const costWarnings = computed(() => cost.value.warnings.map((warning) => {
    const [materialId, , confidence] = warning.details;
    const params = { material: draft.labelOf(materialId), confidence: confidence ? t(`enums.confidence.${confidence}`) : '' };

    return t(`engine.${warning.key}`, params);
}));
const costGapReason = computed(() => {
    if (cost.value.state === 'empty') {
        return 'formulas.cost.reason_empty';
    }

    if (cost.value.state === 'incomplete') {
        return 'formulas.cost.reason_incomplete';
    }

    if (formulaGaps.value.length) {
        return 'formulas.cost.reason_formula';
    }

    return estimateGap.value ? 'formulas.cost.not_reliable_estimate' : 'formulas.cost.reason_prices';
});
const linePrice = (line) => (line.material_id ? cost.value.prices[line.material_id] ?? null : null);
const lineEquivalent = (line) => (line.material_id ? cost.value.equivalents[line.material_id] ?? null : null);
const hasLinePrice = (line) => Boolean(line.material_id) && Boolean(draft.materials[line.material_id]);

/* ---- actions ------------------------------------------------------------ */

function balance() {
    const result = draft.balance();
    balanceErrors.value = result.ok ? [] : result.errors.map(issueText);
}

function chooseQs(key) {
    draft.setQs(key === 'none' ? null : key);
    balanceErrors.value = [];
}

function percentInput(line, value) {
    line.percent = normalizeDecimalText(value);
}

function created(option) {
    draft.useMaterial(option);
}

const canBalance = computed(() => editing.value && draft.qsLine.value !== null);

function submit() {
    tried.value = true;

    if (draft.lines.value.some((line) => !line.material_id)) {
        return;
    }

    const payload = (data) => ({
        ...(creating.value ? { code: data.code, name: data.name, product_type: data.product_type, tier: data.tier === 'none' ? null : data.tier } : { reason: data.reason, balance_events: draft.currentBalanceEvents() }),
        lines: draft.lines.value.map((line) => ({
            trade_material_id: line.material_id,
            percent_ww: line.percent === '' ? null : line.percent,
            is_qs: line.is_qs,
            phase: line.phase === '' ? null : line.phase,
        })),
    });

    form
        .transform(payload)
        .post(creating.value ? path('formulas.store') : path('formulas.versions.store', [props.formula.id, props.version.number]), { preserveScroll: true });
}

const percentValid = (line) => line.percent === '' || isDecimalString(line.percent);
</script>

<template>
    <Head :title="creating ? t('formulas.editor.title_new') : `${formula.code} · ${formula.name}`" />
    <div class="kit-page">
        <PageHeader
            :title="creating ? t('formulas.editor.title_new') : formula.name"
            :description="creating ? t('formulas.editor.description_new') : undefined"
            :back="path('formulas.index')"
            :back-label="t('formulas.title')"
        >
            <template v-if="!creating" #meta>
                <span class="flex flex-wrap items-center gap-2">
                    <bdi dir="ltr" class="font-semibold text-ink-2">{{ formula.code }}</bdi>
                    <Badge tone="neutral" mono>{{ t('formulas.version_short', { version: version.number }) }}</Badge>
                    <StatusBadge kind="formula_status" :status="version.status" />
                    <Badge v-if="!version.is_latest" tone="warning">{{ t('formulas.editor.older_version') }}</Badge>
                </span>
            </template>
            <template v-if="!creating" #actions>
                <Button v-if="history.length > 1" :href="historyHref" variant="secondary" icon="ph:git-diff">{{ t('formulas.compare.open') }}</Button>
                <Button :href="path('formulas.versions.label', [formula.id, version.number])" variant="secondary" icon="ph:tag" data-testid="open-label">{{ t('formulas.label.open') }}</Button>
                <Button v-if="!editing" icon="ph:git-branch" @click="startedNewVersion = true">{{ t('formulas.editor.new_version') }}</Button>
            </template>
        </PageHeader>

        <Alert v-if="!creating && !editing" tone="info" icon="ph:lock-simple" class="mb-4" :title="approved ? t('formulas.editor.approved_title') : t('formulas.editor.readonly_title')">
            {{ approved ? t('formulas.editor.approved_description') : t('formulas.editor.readonly_description') }}
        </Alert>
        <Alert v-else-if="!creating && startedNewVersion" tone="info" icon="ph:git-branch" class="mb-4" :title="t('formulas.editor.new_version_title', { version: version.number })">
            {{ t('formulas.editor.new_version_description') }}
        </Alert>

        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <Card v-if="creating" :title="t('formulas.editor.identity')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField :label="t('formulas.fields.code')" :hint="t('formulas.fields.code_hint')" :error="form.errors.code" required>
                        <Input v-model="form.code" ltr maxlength="50" />
                    </FormField>
                    <FormField :label="t('formulas.fields.name')" :error="form.errors.name" required>
                        <Input v-model="form.name" maxlength="255" />
                    </FormField>
                    <FormField :label="t('formulas.fields.product_type')" :hint="t('formulas.fields.product_type_hint')" :error="form.errors.product_type" required>
                        <Input v-model="form.product_type" maxlength="100" list="product-types" @focus="loadProductTypes" />
                        <datalist id="product-types"><option v-for="type in productTypes" :key="type" :value="type" /></datalist>
                    </FormField>
                    <FormField :label="t('formulas.fields.tier')" :hint="t('formulas.fields.tier_hint')" :error="form.errors.tier">
                        <Select v-model="form.tier" :options="tierOptions" />
                    </FormField>
                </div>
            </Card>
            <Card v-else-if="facts.length">
                <DescriptionList :items="facts" :columns="4" />
            </Card>

            <Card :title="t('formulas.lines.title')">
                <template v-if="editing" #actions>
                    <Button variant="secondary" size="sm" icon="ph:plus-circle" @click="materialModal = true">{{ t('formulas.lines.new_material') }}</Button>
                    <Button variant="secondary" size="sm" icon="ph:plus" @click="draft.addLine()">{{ t('formulas.lines.add') }}</Button>
                </template>

                <p v-if="editing" class="mb-3 text-label text-ink-3">{{ t('formulas.lines.description') }}</p>

                <div class="hidden grid-cols-[minmax(0,2fr)_9rem_8rem_2.5rem] gap-3 px-3 pb-2 text-label font-semibold text-ink-3 md:grid" aria-hidden="true">
                    <span>{{ t('formulas.lines.material') }}</span>
                    <span>{{ t('formulas.lines.percent') }}</span>
                    <span>{{ t('formulas.lines.phase') }}</span>
                    <span />
                </div>

                <ol class="flex flex-col gap-3" data-testid="lines">
                    <li
                        v-for="(line, index) in draft.lines.value"
                        :key="line.key"
                        data-testid="line"
                        class="grid gap-3 rounded-lg border border-line p-3 md:grid-cols-[minmax(0,2fr)_9rem_8rem_2.5rem] md:items-start"
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <EntitySelect
                                v-model="line.material_id"
                                bare
                                label-key="formulas.lines.material_n"
                                :label-params="{ line: index + 1 }"
                                placeholder-key="formulas.lines.material_placeholder"
                                :search-url="urls.materials"
                                :selected-label="draft.labelOf(line.material_id) === line.material_id ? '' : draft.labelOf(line.material_id)"
                                :disabled="!editing"
                                :error="lineError(line, index)"
                                @select="(option) => draft.useMaterial(option, line.key)"
                            />
                            <p v-if="hasLinePrice(line)" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-label text-ink-3">
                                <template v-if="linePrice(line)">
                                    <span>{{ t('formulas.lines.price') }}</span>
                                    <span class="text-ink-2" :data-price-currency="linePrice(line).unit">
                                        <template v-if="linePrice(line).unit === 'EGP'"><Num :value="linePrice(line).value" :decimals="2" /> {{ t('formulas.units.egp_per_kg') }}</template>
                                        <template v-else>
                                            <Num :value="linePrice(line).value" :currency="linePrice(line).unit" :decimals="2" /> {{ t('formulas.units.per_kg') }}
                                            <template v-if="lineEquivalent(line)?.egp !== null"> ≈ <Num :value="lineEquivalent(line).egp" :decimals="2" /> {{ t('formulas.units.egp_per_kg') }}</template>
                                        </template>
                                    </span>
                                    <Badge v-if="lineEquivalent(line)?.missing" tone="warning" size="sm" icon="ph:warning" data-testid="line-fx-missing">{{ t('materials.price.egp_missing_reason') }}</Badge>
                                    <ConfidenceBadge :confidence="linePrice(line).confidence" />
                                </template>
                                <span v-else>{{ t('formulas.lines.no_price') }}</span>
                                <Badge v-if="draft.materials[line.material_id]?.status === 'draft'" tone="warning" size="sm">{{ t('formulas.lines.draft_material') }}</Badge>
                                <a :href="path('materials.show', { material: line.material_id, tab: 'prices' })" target="_blank" rel="noopener" class="text-primary-ink underline underline-offset-2">{{ t('formulas.lines.add_price') }}</a>
                            </p>
                            <FieldError v-if="lineError(line, index)" as="p" size="sm">{{ lineError(line, index) }}</FieldError>
                            <Badge v-if="line.is_qs" tone="info" size="sm" class="self-start">{{ t('formulas.qs_badge') }}</Badge>
                        </div>

                        <Input
                            :model-value="line.percent"
                            :placeholder="line.is_qs ? t('formulas.lines.qs_placeholder') : t('formulas.lines.percent_placeholder')"
                            :aria-label="t('formulas.lines.percent_n', { line: index + 1 })"
                            inputmode="decimal"
                            ltr
                            suffix="%"
                            :readonly="!editing || line.is_qs"
                            :invalid="!percentValid(line) || Boolean(fieldError(index, 'percent_ww')) || lineProblems(line).some((issue) => issue.code !== 'DUPLICATE_MATERIAL')"
                            @update:model-value="(value) => percentInput(line, value)"
                        />
                        <Input
                            v-model="line.phase"
                            :placeholder="t('formulas.lines.phase_placeholder')"
                            :aria-label="t('formulas.lines.phase_n', { line: index + 1 })"
                            maxlength="100"
                            :readonly="!editing"
                        />
                        <IconButton
                            v-if="editing"
                            icon="ph:trash"
                            variant="danger-soft"
                            size="sm"
                            :label="t('formulas.lines.remove_n', { line: index + 1 })"
                            @click="draft.removeLine(line.key)"
                        />
                    </li>
                </ol>
                <FieldError v-if="form.errors.lines" as="p" size="sm" class="mt-2">{{ form.errors.lines }}</FieldError>
            </Card>

            <Card v-if="editing" :title="t('formulas.qs.title')" :description="t('formulas.qs.description')">
                <p v-if="qsOptions.length === 0" class="text-label text-ink-3">{{ t('formulas.qs.empty') }}</p>
                <FormField v-else :label="t('formulas.qs.label')">
                    <RadioGroup
                        :model-value="draft.qsLine.value?.key ?? 'none'"
                        :options="[...qsOptions, { value: 'none', label: t('formulas.qs.none') }]"
                        orientation="horizontal"
                        @update:model-value="chooseQs"
                    />
                </FormField>
                <Alert v-for="message in balanceErrors" :key="message" tone="danger" class="mt-3">{{ message }}</Alert>
            </Card>

            <Card :title="t('formulas.cost.title')" :description="t('formulas.cost.description')" data-testid="cost-card">
                <div class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="type-metric text-ink" data-testid="cost-per-kg">
                            <ExplainableNum :traced="cost.perKgTraced" :value="cost.perKg" :decimals="2" :reason-key="costGapReason" :label="t('formulas.totals.cost')" :entities="entities" :sources="sources" />
                        </span>
                        <span v-if="cost.perKg !== null" class="text-label text-ink-3">{{ t('formulas.units.egp_per_kg') }}</span>
                    </div>
                    <DisplayCurrencyLine :egp="cost.perKg" suffix-key="formulas.units.per_kg" />
                    <p v-if="cost.usdShare" class="flex flex-wrap items-baseline gap-x-1 text-label text-ink-2" data-testid="usd-exposure">
                        <span class="font-semibold text-ink"><ExplainableNum :traced="cost.usdShare" :decimals="1" :label="t('formulas.cost.usd_exposure_label')" :entities="entities" :sources="sources" /> %</span>
                        <span>{{ t('formulas.cost.usd_exposure') }}</span>
                    </p>

                    <Alert v-if="estimateGap" tone="warning" icon="ph:warning" :title="t('formulas.cost.not_reliable_estimate')" data-testid="estimate-warning">
                        {{ t('formulas.cost.not_reliable_description') }}
                    </Alert>
                    <Alert v-if="formulaGaps.length" tone="warning" icon="ph:warning" :title="t('formulas.cost.formula_gap_title')">
                        <ul class="list-disc ps-5"><li v-for="gap in formulaGaps" :key="gap.reason">{{ t(`engine.${gap.reason}`) }}</li></ul>
                    </Alert>

                    <div v-if="priceGaps.length" data-testid="missing-prices">
                        <h3 class="mb-2 type-h6 text-ink">{{ t('formulas.cost.missing_title') }}</h3>
                        <ul class="flex flex-col divide-y divide-line rounded-lg border border-line">
                            <li v-for="gap in priceGaps" :key="gap.materialId" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                <span class="font-semibold text-ink">{{ gap.label }}</span>
                                <span class="min-w-0 flex-1 text-label text-ink-3">{{ reasonsText(gap.reasons) }}</span>
                                <Button v-if="gap.fx" :href="gap.fx.href" variant="link" size="sm" icon="ph:currency-circle-dollar" data-testid="add-rate-link">{{ gap.fx.date ? t('formulas.cost.add_rate', { currency: gap.fx.currency, date: gap.fx.date }) : t('exchange_rates.add') }}</Button>
                                <Button :href="gap.href" variant="link" size="sm" icon="ph:arrow-square-out">{{ t('formulas.cost.add_price') }}</Button>
                            </li>
                        </ul>
                    </div>

                    <ul v-if="costWarnings.length" class="list-disc ps-5 text-label text-ink-3">
                        <li v-for="warning in costWarnings" :key="warning">{{ warning }}</li>
                    </ul>
                </div>
            </Card>

            <CompliancePanel v-if="!creating && compliance" :draft="draft" :formula="formula" :rule-sets="compliance.ruleSets" :entities="entities" :sources="sources" />

            <Card v-if="editing && !creating" :title="t('formulas.reason.title')">
                <FormField :label="t('formulas.reason.label')" :hint="t('formulas.reason.hint')" :error="form.errors.reason" required>
                    <Textarea v-model="form.reason" :rows="2" maxlength="1000" />
                </FormField>
            </Card>

            <Card v-if="!creating" :title="t('formulas.history.title')" :description="t('formulas.history.description')" data-testid="history">
                <ul class="flex flex-col divide-y divide-line">
                    <li v-for="entry in history" :key="entry.number" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" :class="entry.is_current && 'font-semibold'">
                        <Badge tone="neutral" mono>{{ t('formulas.version_short', { version: entry.number }) }}</Badge>
                        <StatusBadge kind="formula_status" :status="entry.status" />
                        <span class="min-w-0 flex-1 text-label text-ink-2">{{ entry.reason ?? t('formulas.history.first_version') }}</span>
                        <span class="text-label text-ink-3">{{ t('formulas.history.lines', { count: entry.lines_count }) }}</span>
                        <bdi dir="ltr" class="text-label text-ink-3 tnum">{{ entry.created_at?.slice(0, 10) }}</bdi>
                        <Tooltip :content="entry.hash"><bdi dir="ltr" class="font-mono text-label text-ink-3" tabindex="0">{{ entry.hash.slice(0, 7) }}</bdi></Tooltip>
                        <span class="flex gap-2">
                            <Button v-if="!entry.is_current" :href="path('formulas.versions.edit', [formula.id, entry.number])" variant="link" size="sm">{{ t('formulas.history.open') }}</Button>
                            <Button v-if="entry.number > 1" :href="path('formulas.compare', { formula: formula.id, from: entry.parent ?? entry.number - 1, to: entry.number })" variant="link" size="sm" icon="ph:git-diff">{{ t('formulas.history.compare') }}</Button>
                        </span>
                    </li>
                </ul>
            </Card>


            <StickyBar show data-testid="totals-bar">
                <span class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <span class="flex flex-col">
                        <span class="eyebrow text-ink-3">{{ t('formulas.totals.sum') }}</span>
                        <span class="type-metric text-ink" data-testid="sum"><ExplainableNum :value="draft.summary.value.sum" :trace="sumTrace?.trace" unit="%w/w" :decimals="3" :label="t('formulas.totals.sum')" :entities="entities" :sources="sources" /> %</span>
                    </span>
                    <span class="flex flex-col gap-1">
                        <Badge v-if="draft.summary.value.withinTolerance" tone="success" icon="ph:check-circle" data-testid="deviation">{{ t('formulas.totals.balanced') }}</Badge>
                        <Badge v-else tone="warning" icon="ph:warning" data-testid="deviation">
                            {{ t('formulas.totals.off_by') }} <Num :value="draft.summary.value.deviation" :decimals="3" :options="{ signDisplay: 'exceptZero' }" />
                        </Badge>
                    </span>
                    <span class="flex flex-col">
                        <span class="eyebrow text-ink-3">{{ t('formulas.totals.cost') }}</span>
                        <span class="flex items-baseline gap-1 text-ink" data-testid="bar-cost">
                            <ExplainableNum :traced="cost.perKgTraced" :value="cost.perKg" :decimals="2" :reason-key="costGapReason" :label="t('formulas.totals.cost')" :entities="entities" :sources="sources" />
                            <span v-if="cost.perKg !== null" class="text-label text-ink-3">{{ t('formulas.units.egp_per_kg') }}</span>
                            <Tooltip v-else-if="estimateGap" :content="t('formulas.cost.not_reliable_estimate')">
                                <Badge tone="danger" icon="ph:warning" tabindex="0">{{ t('formulas.cost.not_reliable_short') }}</Badge>
                            </Tooltip>
                        </span>
                        <DisplayCurrencyLine :egp="cost.perKg" suffix-key="formulas.units.per_kg" data-testid="bar-display-currency" />
                        <span v-if="cost.usdShare" class="flex items-baseline gap-1 text-label text-ink-3" data-testid="bar-usd-exposure">
                            <ExplainableNum :traced="cost.usdShare" :decimals="1" :label="t('formulas.cost.usd_exposure_label')" :entities="entities" :sources="sources" /> % {{ t('formulas.cost.usd_exposure') }}
                        </span>
                    </span>
                </span>
                <template #actions>
                    <Button v-if="canBalance" type="button" variant="secondary" icon="ph:scales" @click="balance">{{ t('formulas.totals.balance') }}</Button>
                    <Button v-if="editing" type="submit" icon="ph:floppy-disk" :loading="form.processing">{{ creating ? t('formulas.save_create') : t('formulas.save_version') }}</Button>
                </template>
            </StickyBar>
        </form>

        <MaterialFormModal v-model:open="materialModal" mode="editor" :urls="urls" @created="created" />
    </div>
</template>
