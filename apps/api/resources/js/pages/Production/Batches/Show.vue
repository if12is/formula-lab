<script setup>
/**
 * One production batch: a snapshot of the approved version (its hash is shown), the ingredients with what was weighed and the lot used,
 * the process steps with their check points and readings, the deviations (why, who, when, and the approval of the ones above the
 * threshold), the waste, the alkali recomputed with the lots' COA values and the real cost. The batch closes only when every
 * line is weighed and no deviation is missing, pending or rejected: the engine's verdicts decide, the page shows why.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, EmptyState, PageHeader, useConfirm } from '@kit';
import AlkaliPanel from '../../../components/production/AlkaliPanel.vue';
import BatchResultsCard from '../../../components/production/BatchResultsCard.vue';
import CheckReadingModal from '../../../components/production/CheckReadingModal.vue';
import CostPanel from '../../../components/production/CostPanel.vue';
import DeviationDecisionModal from '../../../components/production/DeviationDecisionModal.vue';
import DeviationModal from '../../../components/production/DeviationModal.vue';
import ProcessStepsList from '../../../components/production/ProcessStepsList.vue';
import WeighLineModal from '../../../components/production/WeighLineModal.vue';
import Num from '../../../components/Num.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { useDateTime } from '../../../composables/useDateTime';
import { useI18n } from '../../../composables/useI18n';

const props = defineProps({
    batch: { type: Object, required: true },
    order: { type: Object, required: true },
    formula: { type: Object, required: true },
    lines: { type: Array, required: true },
    steps: { type: Array, required: true },
    deviations: { type: Array, required: true },
    blockers: { type: Array, required: true },
    waste: { type: Object, required: true },
    alkali: { type: Object, required: true },
    lots: { type: Array, required: true },
    threshold: { type: String, default: null },
    approval: { type: Object, required: true },
    audience: { type: Object, required: true },
    choices: { type: Object, required: true },
    cost: { type: Object, default: null },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const { confirm } = useConfirm();

const facts = computed(() => [
    { term: t('production.show.formula'), value: `${props.formula.code} · ${props.formula.name} · ${t('formulas.version_short', { version: props.formula.version })}` },
    { term: t('production.show.hash'), key: 'hash' },
    { term: t('production.show.operator'), value: props.batch.operator },
    { term: t('production.batches.started_at'), value: when(props.batch.started_at) },
    { term: t('production.batches.completed_at'), value: props.batch.completed_at ? when(props.batch.completed_at) : t('common.no_data') },
]);

const lineColumns = computed(() => [
    { key: 'line_no', label: '#', hideBelow: 'md' },
    { key: 'material', label: t('production.show.columns.material') },
    { key: 'weigh', label: t('production.batches.columns.to_weigh') },
    { key: 'actual', label: t('production.batches.columns.actual') },
    { key: 'lot', label: t('production.batches.columns.lot'), hideBelow: 'md' },
    ...(props.audience.sees_prices ? [{ key: 'cost', label: t('production.batches.columns.cost'), hideBelow: 'lg' }] : []),
]);

const deviationColumns = computed(() => [
    { key: 'kind', label: t('production.deviations.kind') },
    { key: 'subject', label: t('production.deviations.subject'), hideBelow: 'md' },
    { key: 'numbers', label: t('production.deviations.numbers'), hideBelow: 'md' },
    { key: 'reason', label: t('production.deviations.reason') },
    { key: 'who', label: t('production.deviations.who'), hideBelow: 'lg' },
    { key: 'approval', label: t('production.deviations.approval') },
]);

const VERDICT_TONES = { none: 'success', within: 'success', exceeds: 'danger', threshold_not_set: 'warning', not_evaluated: 'neutral' };
const CHECK_TONES = { PASS: 'success', FAIL: 'danger', UNKNOWN: 'warning' };

const weighLine = ref(null);
const weighOpen = ref(false);
const weighUrl = computed(() => (weighLine.value ? props.urls.weigh.replace('__LINE__', weighLine.value.id) : null));
function openWeigh(line) {
    weighLine.value = line;
    weighOpen.value = true;
}

const checkPoint = ref(null);
const checkOpen = ref(false);
function openCheck(step, check) {
    checkPoint.value = { step: step.step_no, check };
    checkOpen.value = true;
}

const deviationPreset = ref(null);
const deviationOpen = ref(false);
function openDeviation(preset = null) {
    deviationPreset.value = preset;
    deviationOpen.value = true;
}
function deviateLine(line) {
    openDeviation({ kind: 'weight', subject: `line:${line.line_no}`, planned: line.weigh, actual: line.actual ?? '', unit: line.uom === 'unit' ? 'unit' : 'g' });
}

const decisionTarget = ref(null);
const decisionKind = ref('approve');
const decisionOpen = ref(false);
function openDecision(deviation, kind) {
    decisionTarget.value = deviation;
    decisionKind.value = kind;
    decisionOpen.value = true;
}

const materialsByLine = computed(() => Object.fromEntries(props.lines.map((line) => [String(line.line_no), line.material])));

async function complete() {
    const confirmed = await confirm({ title: t('production.batches.complete_title'), description: t('production.batches.complete_description'), confirmLabel: t('production.batches.complete') });
    if (confirmed) {
        router.post(props.urls.complete, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('production.batches.title', { batch: batch.number })" />
    <div class="kit-page">
        <PageHeader :title="t('production.batches.title', { batch: batch.number })" :description="t('production.batches.description')">
            <template #actions>
                <StatusBadge kind="production_batch_status" :status="batch.status" />
                <Button variant="ghost" :href="order.href" icon="ph:arrow-right">{{ t('production.show.back_to_order') }}</Button>
                <Button v-if="batch.open" variant="secondary" icon="ph:warning-circle" data-testid="record-deviation" @click="openDeviation()">{{ t('production.deviations.record') }}</Button>
                <Button v-if="batch.open" icon="ph:check-circle" :disabled="blockers.length > 0" data-testid="complete-batch" @click="complete">{{ t('production.batches.complete') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Card>
                <DescriptionList :items="facts" :columns="3">
                    <template #hash><bdi dir="ltr" class="break-all font-mono text-label" data-testid="batch-hash">{{ batch.formula_hash }}</bdi></template>
                </DescriptionList>
            </Card>

            <Alert v-if="!batch.open" tone="info" icon="ph:lock-simple" :title="t('production.batches.closed_title')">{{ t('production.batches.closed_description') }}</Alert>
            <Alert v-else-if="blockers.length" tone="warning" icon="ph:hourglass" :title="t('production.batches.blockers_title')" data-testid="complete-blockers">
                <ul class="list-disc ps-5"><li v-for="blocker in blockers" :key="blocker">{{ blocker }}</li></ul>
            </Alert>

            <Card :title="t('production.batches.lines_title')" :description="t('production.batches.lines_description')" data-testid="lines-card">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" flush :mobile-columns="['material', 'weigh', 'actual']" :title="t('production.batches.lines_title')">
                    <template #cell-line_no="{ row }"><Badge tone="neutral" mono>{{ row.line_no }}</Badge></template>
                    <template #cell-material="{ row }"><span class="flex flex-col"><span class="text-ink">{{ row.material }}</span><span v-if="row.kind === 'packaging'" class="text-label text-ink-3">{{ t('enums.batch_line_kind.packaging') }}</span></span></template>
                    <template #cell-weigh="{ row }"><span class="tnum" :data-weigh="row.line_no"><bdi dir="ltr">{{ row.weigh }} {{ t(`production.units.${row.uom}`) }}</bdi></span></template>
                    <template #cell-actual="{ row }">
                        <span class="flex flex-col gap-1">
                            <span v-if="row.actual !== null" class="tnum font-semibold text-ink" :data-actual="row.line_no"><bdi dir="ltr">{{ row.actual }} {{ t(`production.units.${row.uom}`) }}</bdi></span>
                            <Badge v-else tone="warning" icon="ph:hourglass" size="sm" :data-actual="row.line_no">{{ t('production.batches.not_weighed') }}</Badge>
                            <span v-if="row.deviation" class="flex flex-wrap items-center gap-2" :data-verdict="row.line_no" :data-state="row.deviation.verdict">
                                <Badge :tone="VERDICT_TONES[row.deviation.verdict]" dot size="sm">{{ t(`enums.deviation_verdict.${row.deviation.verdict}`) }}</Badge>
                                <span v-if="row.deviation.pct && row.deviation.verdict !== 'none'" class="tnum text-label text-ink-3"><bdi dir="ltr">{{ row.deviation.pct }}%</bdi></span>
                                <Badge v-if="row.deviation_recorded" tone="info" size="sm" icon="ph:note">{{ t('production.deviations.recorded') }}</Badge>
                            </span>
                            <span v-if="batch.open" class="flex flex-wrap gap-2">
                                <Button size="sm" variant="secondary" icon="ph:scales" :data-testid="`weigh-${row.line_no}`" @click="openWeigh(row)">{{ t('production.batches.record_weight') }}</Button>
                                <Button v-if="row.deviation && ['exceeds', 'threshold_not_set'].includes(row.deviation.verdict) && !row.deviation_recorded" size="sm" variant="ghost" icon="ph:warning-circle" :data-testid="`deviate-${row.line_no}`" @click="deviateLine(row)">{{ t('production.deviations.record') }}</Button>
                            </span>
                        </span>
                    </template>
                    <template #cell-lot="{ row }"><Badge v-if="row.lot" tone="neutral" mono :data-lot-of="row.line_no">{{ row.lot }}</Badge><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
                    <template #cell-cost="{ row }">
                        <span v-if="row.unit_cost" class="tnum"><bdi dir="ltr">{{ row.unit_cost }} / {{ t(`production.units.${row.cost_uom === 'kg' ? 'kg' : 'unit'}`) }}</bdi> <Badge tone="neutral" size="sm">{{ t(`production.batches.cost_source.${row.unit_cost_source}`) }}</Badge></span>
                        <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                    </template>
                </DataTable>
            </Card>

            <Card :title="t('production.show.steps_title')" :description="t('production.batches.steps_description')" data-testid="steps-card">
                <ProcessStepsList v-if="steps.length" :steps="steps" :materials="materialsByLine">
                    <template #check="{ step, check }">
                        <div class="flex flex-wrap items-center gap-2">
                            <template v-if="check.latest">
                                <Badge :tone="CHECK_TONES[check.latest.status]" dot size="sm" :data-reading="`${step.step_no}/${check.check_no}`" :data-status="check.latest.status">{{ t(`enums.check_status.${check.latest.status}`) }}</Badge>
                                <span class="tnum text-ink"><bdi dir="ltr">{{ check.latest.value }} {{ check.unit }}</bdi></span>
                                <span class="text-label text-ink-3">{{ check.latest.measured_by }} · {{ when(check.latest.measured_at) }}</span>
                            </template>
                            <span v-else class="text-label text-ink-3">{{ t('production.checks.no_reading') }}</span>
                            <Button v-if="batch.open" size="sm" variant="secondary" icon="ph:ruler" :data-testid="`check-${step.step_no}-${check.check_no}`" @click="openCheck(step, check)">{{ t('production.checks.record') }}</Button>
                        </div>
                    </template>
                </ProcessStepsList>
                <EmptyState v-else variant="dashed" icon="ph:list-numbers" :title="t('production.show.no_steps')" />
            </Card>

            <Card :title="t('production.deviations.title')" :description="t('production.deviations.description')" data-testid="deviations-card">
                <Alert v-if="threshold === null" tone="warning" icon="ph:warning" class="mb-3" data-testid="threshold-not-set">{{ t('production.deviations.threshold_not_set') }}</Alert>
                <DataTable v-if="deviations.length" :columns="deviationColumns" :rows="deviations" row-key="id" flush :mobile-columns="['kind', 'reason', 'approval']" :title="t('production.deviations.title')">
                    <template #cell-kind="{ row }"><Badge tone="neutral" size="sm">{{ t(`enums.deviation_kind.${row.kind}`) }}</Badge></template>
                    <template #cell-subject="{ row }"><bdi dir="ltr">{{ row.subject }}</bdi></template>
                    <template #cell-numbers="{ row }">
                        <span v-if="row.planned !== null" class="tnum"><bdi dir="ltr">{{ row.planned }} → {{ row.actual }} {{ row.unit }}</bdi><span v-if="row.deviation_pct" class="ms-2 text-ink-3"><Num :value="row.deviation_pct" :decimals="3"><template #suffix> %</template></Num></span></span>
                        <span v-else class="text-ink-3">{{ t('enums.deviation_verdict.not_evaluated') }}</span>
                    </template>
                    <template #cell-reason="{ row }"><span class="text-ink" :data-reason="row.id">{{ row.reason }}</span></template>
                    <template #cell-who="{ row }"><span class="flex flex-col text-label"><span>{{ row.recorded_by_name }}</span><span class="text-ink-3">{{ when(row.recorded_at) }}</span></span></template>
                    <template #cell-approval="{ row }">
                        <span class="flex flex-col gap-1" :data-approval="row.approval_status">
                            <Badge :tone="{ not_required: 'neutral', pending: 'warning', approved: 'success', rejected: 'danger' }[row.approval_status]" dot size="sm">{{ t(`enums.deviation_approval_status.${row.approval_status}`) }}</Badge>
                            <span v-if="row.decided_at" class="text-label text-ink-3">{{ row.decided_by_name }} · {{ when(row.decided_at) }}</span>
                            <span v-if="row.decision_note" class="text-label text-ink-2">{{ row.decision_note }}</span>
                            <span v-if="row.approval_status === 'pending'" class="flex flex-wrap gap-2">
                                <Button size="sm" icon="ph:check" :disabled="!approval.available" :data-testid="`approve-${row.id}`" @click="openDecision(row, 'approve')">{{ t('production.deviations.approve') }}</Button>
                                <Button size="sm" variant="danger" icon="ph:x" :disabled="!approval.available" :data-testid="`reject-${row.id}`" @click="openDecision(row, 'reject')">{{ t('production.deviations.reject') }}</Button>
                            </span>
                        </span>
                    </template>
                </DataTable>
                <EmptyState v-else variant="dashed" icon="ph:seal-check" :title="t('production.deviations.none')" />
                <Alert v-if="deviations.some((row) => row.approval_status === 'pending') && !approval.available" tone="info" icon="ph:lock-simple" class="mt-3" data-testid="approval-unavailable">{{ t(approval.why) }}</Alert>
            </Card>

            <BatchResultsCard :batch="batch" :waste="waste" :sees-prices="audience.sees_prices" :url="urls.results" />

            <AlkaliPanel :alkali="alkali" :url="urls.self" />

            <CostPanel v-if="cost" :cost="cost" />

            <Card v-if="lots.length" :title="t('production.trace.batch_lots')" data-testid="batch-lots">
                <ul class="flex flex-col gap-1">
                    <li v-for="lot in lots" :key="`${lot.material}-${lot.lot}`" class="flex flex-wrap items-center gap-2"><span>{{ lot.material }}</span><Badge tone="neutral" mono>{{ lot.lot }}</Badge><Button :href="lot.href" variant="link" size="sm" icon="ph:path">{{ t('production.trace.where_used') }}</Button></li>
                </ul>
            </Card>
        </div>

        <WeighLineModal v-model:open="weighOpen" :line="weighLine" :url="weighUrl" :sees-prices="audience.sees_prices" :operator="batch.operator" />
        <CheckReadingModal v-model:open="checkOpen" :point="checkPoint" :url="urls.checks" :operator="batch.operator" />
        <DeviationModal v-model:open="deviationOpen" :preset="deviationPreset" :choices="choices" :threshold="threshold" :url="urls.deviations" :operator="batch.operator" />
        <DeviationDecisionModal v-model:open="decisionOpen" :deviation="decisionTarget" :decision="decisionKind" />
    </div>
</template>
