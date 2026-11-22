<script setup>
/**
 * The auditable cost report of a formula version (I-08). Every figure is the engine's (the same calculation as the editor's
 * cost), and every line says where its number came from: the price used, its source (an invoice or a manual entry), confidence
 * and date, the prices that were not used and why, and the Sanad purchase invoices on file. Any figure opens its calculation
 * tree. A price that is missing is a named gap, never a zero. The page prints as a document (the kit hides the shell).
 * The same page holds the cost approval that publishing to Sanad needs.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DescriptionList, FormField, PageHeader, Select, Textarea } from '@kit';
import ExplainableNum from '../../components/explain/ExplainableNum.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import Num from '../../components/Num.vue';
import UnitCostModal from '../../components/pricing/UnitCostModal.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import { path } from '../../lib/routes';
import { REJECT_REASON_KEYS } from '../../lib/engine';

const props = defineProps({
    report: { type: Object, required: true },
    approval: { type: Object, default: null },
    versions: { type: Array, required: true },
    canApprove: { type: Boolean, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const costOpen = ref(false);
const form = useForm({ comment: '' });

const formula = computed(() => props.report.formula);
const versionOptions = computed(() => props.versions.map((v) => ({ value: String(v.number), label: `${t('cost_report.version')} ${v.number} · ${t(`enums.formula_status.${v.status}`)}` })));
const costRow = computed(() => ({
    formula_id: formula.value.id,
    code: formula.value.code,
    name: formula.value.name,
    unit_cost: props.report.unit_cost ? { value: props.report.unit_cost.value, source_id: props.report.unit_cost.source_id, source_title: props.report.unit_cost.source_title, confidence: props.report.unit_cost.confidence } : null,
}));
const inForce = computed(() => props.approval !== null && props.approval.stale_reason === null);

const summary = computed(() => [
    { term: t('cost_report.summary.engine'), value: props.report.engine.version, mono: true },
    { term: t('cost_report.summary.dataset'), value: props.report.engine.dataset, mono: true },
    { term: t('cost_report.summary.formula_hash'), value: props.report.version.hash, mono: true },
    { term: t('cost_report.summary.input_hash'), value: props.report.engine.input_hash, mono: true },
    { term: t('cost_report.summary.output_hash'), value: props.report.engine.output_hash, mono: true },
]);

const materialHref = (id) => path('materials.show', { material: id, tab: 'prices' });
const pickVersion = (value) => router.get(path('formulas.cost-report', formula.value.id), { version: value }, { preserveState: false });
const print = () => window.print();
const approve = () => form.post(path('formulas.cost-approvals.store', formula.value.id), { preserveScroll: true, onSuccess: () => form.reset() });
</script>

<template>
    <Head :title="`${t('cost_report.title')} · ${formula.code}`" />
    <div class="kit-page" data-testid="cost-report">
        <PageHeader :title="t('cost_report.title')" :description="`${formula.code} · ${formula.name}`">
            <template #actions>
                <Button variant="secondary" icon="ph:printer" class="print:hidden" data-testid="print-report" @click="print">{{ t('cost_report.print') }}</Button>
                <Button :href="path('formulas.publish', formula.id)" icon="ph:paper-plane-tilt" class="print:hidden" data-testid="to-publish">{{ t('cost_report.publish') }}</Button>
            </template>
        </PageHeader>

        <p class="mb-4 text-label text-ink-3">{{ t('cost_report.description') }}</p>

        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <FormField :label="t('cost_report.version')" class="w-full sm:max-w-xs">
                    <Select :model-value="String(report.version.number)" :options="versionOptions" data-testid="version-select" @update:model-value="pickVersion" />
                </FormField>
                <Button :href="path('formulas.versions.edit', { formula: formula.id, version: report.version.number })" variant="link" icon="ph:arrow-u-up-left">{{ t('cost_report.back_to_formula') }}</Button>
            </div>

            <Card :title="t('cost_report.summary.title')" data-testid="report-summary">
                <div class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-baseline gap-3">
                        <span class="type-metric text-ink" data-testid="report-total">
                            <ExplainableNum :traced="report.total" :decimals="2" :label="t('cost_report.summary.total')" :entities="report.entities" :sources="report.sources" reason-key="formulas.cost.reason_prices" />
                        </span>
                        <span v-if="report.total" class="text-label text-ink-3">{{ t('cost_report.summary.unit') }}</span>
                        <StatusBadge kind="formula_status" :status="report.version.status" />
                        <Badge :tone="report.ok ? 'success' : 'warning'" dot>{{ report.ok ? t('enums.check_status.PASS') : t('enums.check_status.UNKNOWN') }}</Badge>
                    </div>
                    <Alert v-if="!report.ok" tone="warning" icon="ph:warning" data-testid="report-incomplete">{{ t('cost_report.summary.incomplete') }}</Alert>
                    <DescriptionList :items="summary" :columns="2" class="break-all" />
                </div>
            </Card>

            <Card v-if="report.missing.length" :title="t('cost_report.gaps.title')" data-testid="report-gaps">
                <ul class="flex flex-col gap-2">
                    <li v-for="(gap, index) in report.missing" :key="index" class="flex flex-wrap items-center gap-x-2 text-ink">
                        <span class="font-semibold">{{ gap.material ?? t('cost_report.product') }}</span>
                        <span class="text-ink-2">{{ gap.reason }}</span>
                        <Button v-if="gap.href" :href="gap.href" variant="link" size="sm" icon="ph:arrow-square-out" class="print:hidden">{{ t('cost_report.gaps.fix') }}</Button>
                    </li>
                </ul>
            </Card>

            <Card :title="t('cost_report.lines.title')" data-testid="report-lines">
                <ol class="flex flex-col gap-4">
                    <li v-for="line in report.lines" :key="line.material_id" class="flex flex-col gap-3 rounded-lg border border-line p-4" :data-testid="`report-line-${line.material}`">
                        <div class="flex flex-wrap items-center gap-2">
                            <a :href="materialHref(line.material_id)" class="font-semibold text-primary-ink underline underline-offset-2">{{ line.material }}</a>
                            <Badge v-if="line.is_qs" tone="neutral" size="sm">{{ t('cost_report.lines.qs') }}</Badge>
                            <span class="text-ink-3">{{ t('cost_report.lines.percent') }}</span>
                            <span class="text-ink"><Num :value="line.percent_ww" /> %</span>
                        </div>

                        <div v-if="line.quote" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="flex flex-col gap-1">
                                <span class="text-label text-ink-3">{{ t('cost_report.lines.price') }}</span>
                                <span class="text-ink" data-testid="line-price"><Num :value="line.quote.amount" /> {{ line.quote.currency }} / {{ line.quote.per_unit }}</span>
                                <span v-if="line.quote.fx" class="text-label text-ink-3">{{ t('cost_report.lines.fx', { rate: line.quote.fx.rate, date: line.quote.fx.rate_date, source: line.quote.fx.source_title }) }}</span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-label text-ink-3">{{ t('cost_report.lines.origin') }}</span>
                                <span class="flex flex-wrap items-center gap-2">
                                    <Badge :tone="line.quote.origin === 'invoice' ? 'success' : 'neutral'" size="sm" :icon="line.quote.origin === 'invoice' ? 'ph:receipt' : 'ph:pencil-simple'" data-testid="line-origin" :data-origin="line.quote.origin">{{ t(`cost_report.origin.${line.quote.origin}`) }}</Badge>
                                    <ConfidenceBadge :confidence="line.quote.confidence" />
                                </span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-label text-ink-3">{{ t('cost_report.lines.source') }}</span>
                                <span class="text-ink" data-testid="line-source">
                                    <a v-if="line.quote.source_url" :href="line.quote.source_url" target="_blank" rel="noopener" class="text-primary-ink underline underline-offset-2">{{ line.quote.source_title }}</a>
                                    <template v-else>{{ line.quote.source_title }}</template>
                                </span>
                                <span class="text-label text-ink-3">{{ t('cost_report.lines.dated') }} <bdi dir="ltr">{{ line.quote.quoted_on }}</bdi></span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-label text-ink-3">{{ t('cost_report.lines.share') }}</span>
                                <span class="text-ink" data-testid="line-share">
                                    <ExplainableNum :value="line.contribution_egp_per_kg" :trace="line.trace" :decimals="4" :label="`${line.material}: ${t('cost_report.lines.share')}`" :entities="report.entities" :sources="report.sources" /> {{ t('cost_report.summary.unit') }}
                                </span>
                                <span class="text-label text-ink-3">{{ t('cost_report.lines.in_egp') }}: <Num :value="line.price_egp_per_kg" :decimals="4" /></span>
                            </div>
                        </div>
                        <Alert v-else tone="warning" icon="ph:warning">{{ t('cost_report.lines.no_price') }}</Alert>

                        <div v-if="line.rejected.length" class="flex flex-col gap-1" data-testid="line-rejected">
                            <span class="text-label text-ink-3">{{ t('cost_report.lines.rejected_title') }}</span>
                            <ul class="flex flex-col gap-1 text-label text-ink-2">
                                <li v-for="(item, index) in line.rejected" :key="index" class="flex flex-wrap items-center gap-2">
                                    <Num :value="item.quote.amount" /> {{ item.quote.currency }}
                                    <ConfidenceBadge :confidence="item.quote.confidence" />
                                    <bdi dir="ltr">{{ item.quote.quoted_on }}</bdi>
                                    <span>{{ item.quote.source_title }}</span>
                                    <Badge tone="neutral" size="sm">{{ t(REJECT_REASON_KEYS[item.reason] ?? 'materials.resolve.reasons.invalid') }}</Badge>
                                </li>
                            </ul>
                        </div>

                        <div class="flex flex-col gap-1" data-testid="line-invoices">
                            <span class="text-label text-ink-3">{{ t('cost_report.lines.invoices_title') }}</span>
                            <ul v-if="line.invoices.length" class="flex flex-col gap-1 text-label text-ink-2">
                                <li v-for="invoice in line.invoices" :key="invoice.purchase_uuid" class="flex flex-wrap items-center gap-x-2">
                                    <Badge tone="info" size="sm" icon="ph:receipt">{{ invoice.supplier ?? t('common.no_data') }}</Badge>
                                    {{ t('cost_report.lines.invoice_line', { date: invoice.purchase_date, quantity: invoice.quantity ?? '—', uom: invoice.purchase_uom ?? '', cost: invoice.unit_cost ?? '—', currency: invoice.currency }) }}
                                </li>
                            </ul>
                            <span v-else class="text-label text-ink-3">{{ t('cost_report.lines.invoices_none') }}</span>
                        </div>
                    </li>
                </ol>
            </Card>

            <Card :title="t('cost_report.unit_cost.title')" :description="t('cost_report.unit_cost.description')" data-testid="report-unit-cost">
                <div v-if="report.unit_cost" class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <span class="type-metric text-ink"><Num :value="report.unit_cost.value" :decimals="2" /></span>
                    <span class="text-label text-ink-3">{{ t('cost_report.unit_cost.unit') }}</span>
                    <Badge :tone="report.unit_cost.origin === 'invoice' ? 'success' : 'neutral'" size="sm">{{ t(`cost_report.origin.${report.unit_cost.origin}`) }}</Badge>
                    <ConfidenceBadge :confidence="report.unit_cost.confidence" />
                    <span class="text-ink-2">{{ report.unit_cost.source_title }}</span>
                    <bdi dir="ltr" class="text-label text-ink-3">{{ report.unit_cost.retrieved_at }}</bdi>
                </div>
                <Alert v-else tone="warning" icon="ph:warning" data-testid="no-unit-cost">{{ t('cost_report.unit_cost.none') }}</Alert>
                <Button variant="secondary" size="sm" icon="ph:pencil-simple" class="mt-3 print:hidden" data-testid="set-unit-cost" @click="costOpen = true">{{ t('price_lists.cost.title') }}</Button>
            </Card>

            <Card :title="t('cost_report.approval.title')" :description="t('cost_report.approval.description')" class="print:hidden" data-testid="cost-approval">
                <div class="flex flex-col gap-4">
                    <Alert v-if="approval === null" tone="warning" icon="ph:warning" data-testid="approval-none">{{ t('cost_report.approval.none') }}</Alert>
                    <template v-else>
                        <Badge :tone="inForce ? 'success' : 'warning'" dot class="self-start" data-testid="approval-state" :data-state="inForce ? 'in_force' : approval.stale_reason">{{ inForce ? t('cost_report.approval.in_force') : t(`cost_report.approval.stale.${approval.stale_reason}`) }}</Badge>
                        <DescriptionList
                            :items="[
                                { term: t('cost_report.approval.unit_cost'), value: approval.unit_cost, mono: true },
                                { term: t('cost_report.approval.version'), value: String(approval.version), mono: true },
                                { term: t('cost_report.approval.by'), value: approval.approved_by ?? t('common.no_data') },
                                { term: t('cost_report.approval.at'), value: when(approval.approved_at) },
                                ...(approval.comment ? [{ term: t('cost_report.approval.comment'), value: approval.comment }] : []),
                            ]"
                            :columns="2"
                        />
                    </template>
                    <form v-if="canApprove" class="flex flex-col gap-3" novalidate data-testid="approval-form" @submit.prevent="approve">
                        <FormField :label="t('cost_report.approval.comment')" :hint="t('cost_report.approval.comment_hint')" :error="form.errors.comment">
                            <Textarea v-model="form.comment" :rows="2" maxlength="1000" />
                        </FormField>
                        <Button type="submit" icon="ph:seal-check" class="self-start" :loading="form.processing" data-testid="approve-cost">{{ t('cost_report.approval.submit') }}</Button>
                    </form>
                    <Alert v-else tone="info" icon="ph:info">{{ t('cost_report.approval.needs_approved_version') }}</Alert>
                </div>
            </Card>

            <Alert v-if="report.warnings.length" tone="info" icon="ph:info" :title="t('cost_report.warnings_title')">
                <ul class="list-disc ps-5"><li v-for="warning in report.warnings" :key="warning">{{ warning }}</li></ul>
            </Alert>
            <p class="hidden text-label text-ink-3 print:block">{{ t('cost_report.printed_note') }}</p>
        </div>

        <UnitCostModal v-model:open="costOpen" :row="costRow" :confidence-options="confidenceOptions" :source-kind-options="sourceKindOptions" :urls="urls" />
    </div>
</template>
