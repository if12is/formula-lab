<script setup>
/**
 * One production order: its facts and the hash of the approved version it was made from, the snapshot (lines, steps, packaging),
 * the weighing sheet the engine worked out, and where it stands with Sanad. An operator (a weights-only role) is sent the order,
 * the sheet and the batch only: the cost and the Sanad message are not in what the server sends. Stock is Sanad's: sending the
 * order asks Sanad to consume the materials and receive the finished good.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, EmptyState, FormField, Input, Modal, PageHeader, useConfirm } from '@kit';
import DecimalField from '../../../components/form/DecimalField.vue';
import Num from '../../../components/Num.vue';
import ProcessStepsList from '../../../components/production/ProcessStepsList.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { useDateTime } from '../../../composables/useDateTime';
import { useI18n } from '../../../composables/useI18n';

const props = defineProps({
    order: { type: Object, required: true },
    formula: { type: Object, required: true },
    snapshot: { type: Object, required: true },
    sheet: { type: Object, required: true },
    batch: { type: Object, default: null },
    approval: { type: Object, required: true },
    audience: { type: Object, required: true },
    can: { type: Object, required: true },
    cost: { type: Object, default: null },
    sanad: { type: Object, default: null },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const { confirm } = useConfirm();

const facts = computed(() => [
    { term: t('production.show.formula'), key: 'formula' },
    { term: t('production.show.hash'), key: 'hash' },
    { term: t('production.show.batch_size'), value: `${props.order.batch_size} ${props.order.batch_unit}`, mono: true },
    { term: t('production.show.resolution'), value: `${props.order.scale_resolution_g} ${t('production.units.g')}`, mono: true },
    { term: t('production.show.units_count'), value: props.order.units_count ?? t('common.no_data'), mono: true },
    { term: t('production.show.batch_number'), value: props.order.batch_number, mono: true },
    { term: t('production.show.planned_on'), value: props.order.planned_on, mono: true },
    { term: t('production.show.expiry_date'), value: props.order.expiry_date ?? t('common.no_data'), mono: true },
    { term: t('production.show.branch_code'), value: props.order.branch_code ?? t('production.show.main_branch') },
    { term: t('production.show.approved'), value: props.order.approved_at ? `${props.order.approved_by ?? t('production.show.unknown_person')} · ${when(props.order.approved_at)}` : t('production.show.not_approved') },
    ...(props.order.notes ? [{ term: t('production.show.notes'), value: props.order.notes }] : []),
]);

const lineColumns = computed(() => [
    { key: 'line_no', label: '#', hideBelow: 'md' },
    { key: 'material', label: t('production.show.columns.material') },
    { key: 'percent', label: t('production.show.columns.percent') },
    { key: 'phase', label: t('production.show.columns.phase'), hideBelow: 'md' },
    { key: 'temp', label: t('production.show.columns.temp'), hideBelow: 'lg' },
]);

const sheetColumns = computed(() => [
    { key: 'order', label: '#' },
    { key: 'material', label: t('production.show.columns.material') },
    { key: 'weigh', label: t('production.show.columns.weigh') },
    { key: 'exact', label: t('production.show.columns.exact'), hideBelow: 'md' },
]);

const materialsByLine = computed(() => Object.fromEntries(props.snapshot.lines.map((line) => [line.line_no, line.material])));

async function approve() {
    const confirmed = await confirm({ title: t('production.show.approve_title'), description: t('production.show.approve_description'), confirmLabel: t('production.show.approve') });
    if (confirmed) {
        router.post(props.urls.approve, {}, { preserveScroll: true });
    }
}

async function send() {
    const confirmed = await confirm({ title: t('production.show.send_title'), description: t('production.show.send_description'), confirmLabel: t('production.show.send') });
    if (confirmed) {
        router.post(props.urls.send, {}, { preserveScroll: true });
    }
}

const starting = ref(false);
const batchForm = useForm({ operator: '', planned_waste_pct: '' });
function startBatch() {
    batchForm.post(props.urls.startBatch, { onSuccess: () => { starting.value = false; } });
}
</script>

<template>
    <Head :title="order.code" />
    <div class="kit-page">
        <PageHeader :title="order.code" :description="t('production.show.description')">
            <template #actions>
                <StatusBadge kind="production_order_status" :status="order.status" />
                <Button variant="ghost" :href="urls.index" icon="ph:arrow-right">{{ t('production.show.back') }}</Button>
                <Button variant="secondary" icon="ph:scales" :href="urls.weighing" data-testid="open-weighing">{{ t('production.show.open_weighing') }}</Button>
                <Button v-if="can.approve" icon="ph:seal-check" :disabled="!approval.available" data-testid="approve-order" @click="approve">{{ t('production.show.approve') }}</Button>
                <Button v-if="can.send && audience.sees_prices" icon="ph:paper-plane-tilt" :disabled="sanad?.blockers?.length > 0" data-testid="send-order" @click="send">{{ t('production.show.send') }}</Button>
                <Button v-if="batch" icon="ph:flask" :href="batch.href" data-testid="open-batch">{{ t('production.show.open_batch') }}</Button>
                <Button v-else-if="can.startBatch" icon="ph:play" data-testid="start-batch" @click="starting = true">{{ t('production.show.start_batch') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Alert v-if="can.approve && !approval.available" tone="info" icon="ph:lock-simple" data-testid="approval-unavailable">{{ t(approval.why) }}</Alert>

            <Card>
                <DescriptionList :items="facts" :columns="3">
                    <template #formula>
                        <a :href="formula.editorHref" class="text-primary-ink underline underline-offset-2" data-testid="formula-link">
                            <bdi dir="ltr">{{ formula.code }}</bdi> · {{ formula.name }}
                        </a>
                        <Badge tone="neutral" mono class="ms-2">{{ t('formulas.version_short', { version: formula.version }) }}</Badge>
                    </template>
                    <template #hash><bdi dir="ltr" class="break-all font-mono text-label" data-testid="order-hash">{{ order.formula_hash }}</bdi></template>
                </DescriptionList>
            </Card>

            <Card :title="t('production.show.sheet_title')" :description="t('production.show.sheet_description')" data-testid="sheet-card">
                <Alert v-if="!sheet.ok" tone="danger" icon="ph:warning" :title="t('production.show.sheet_blocked')">
                    <ul class="list-disc ps-5"><li v-for="reason in sheet.missing" :key="reason">{{ reason }}</li></ul>
                </Alert>
                <template v-else>
                    <DataTable :columns="sheetColumns" :rows="sheet.sheet.lines" row-key="lineNo" flush :mobile-columns="['material', 'weigh']" :title="t('production.show.sheet_title')">
                        <template #cell-order="{ row }"><Badge tone="neutral" mono>{{ row.order }}</Badge></template>
                        <template #cell-weigh="{ row }"><span class="tnum font-semibold text-ink" :data-weigh="row.lineNo"><bdi dir="ltr">{{ row.weighG }} {{ t('production.units.g') }}</bdi></span></template>
                        <template #cell-exact="{ row }"><span class="tnum text-ink-3"><bdi dir="ltr">{{ row.exactG }} {{ t('production.units.g') }}</bdi></span></template>
                    </DataTable>
                    <p class="mt-3 text-label text-ink-3" data-testid="sheet-total">
                        {{ t('production.show.sheet_total') }}: <bdi dir="ltr" class="tnum">{{ sheet.sheet.totalWeighG }} {{ t('production.units.g') }}</bdi>
                        <template v-if="sheet.sheet.rounding.hasResidual"> · {{ t('production.show.sheet_residual') }}: <bdi dir="ltr" class="tnum">{{ sheet.sheet.rounding.residual }} {{ t('production.units.g') }}</bdi></template>
                    </p>
                </template>
            </Card>

            <Card :title="t('production.show.lines_title')" :description="t('production.show.lines_description')">
                <DataTable :columns="lineColumns" :rows="snapshot.lines" row-key="line_no" flush :mobile-columns="['material', 'percent']" :title="t('production.show.lines_title')">
                    <template #cell-line_no="{ row }"><Badge tone="neutral" mono>{{ row.line_no }}</Badge></template>
                    <template #cell-percent="{ row }"><Num v-if="row.percent_ww" :value="row.percent_ww"><template #suffix> %</template></Num><Badge v-else tone="info" size="sm">q.s.</Badge></template>
                    <template #cell-phase="{ row }"><span v-if="row.phase">{{ t(`enums.formula_phase.${row.phase}`) }}</span><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
                    <template #cell-temp="{ row }"><Num :value="row.addition_temp_c"><template #suffix> °C</template></Num></template>
                </DataTable>
            </Card>

            <Card :title="t('production.show.steps_title')" :description="t('production.show.steps_description')">
                <ProcessStepsList v-if="snapshot.steps.length" :steps="snapshot.steps" :materials="materialsByLine" />
                <EmptyState v-else variant="dashed" icon="ph:list-numbers" :title="t('production.show.no_steps')" />
            </Card>

            <Card v-if="snapshot.packaging.length" :title="t('production.show.packaging_title')">
                <ul class="flex flex-col gap-1">
                    <li v-for="line in snapshot.packaging" :key="line.trade_material_id" class="flex flex-wrap items-center gap-3"><span class="text-ink">{{ line.material }}</span><Num :value="line.quantity"><template #suffix> {{ t('production.units.unit') }}</template></Num></li>
                </ul>
            </Card>

            <Card v-if="audience.sees_prices" :title="t('production.show.sanad_title')" :description="t('production.show.sanad_description')" data-testid="sanad-card">
                <div class="flex flex-col gap-3">
                    <p v-if="cost" class="text-ink-2">{{ t('production.show.unit_cost') }}: <Num :value="cost.unit_cost"><template #suffix> {{ t('production.units.egp') }}</template></Num></p>
                    <p v-else class="text-ink-3">{{ t('production.show.unit_cost') }}: <Num :value="null" reason-key="production.show.cost_not_approved" /></p>

                    <Alert v-if="sanad.blockers.length" tone="warning" icon="ph:warning" :title="t('production.show.blockers_title')" data-testid="send-blockers">
                        <ul class="list-disc ps-5"><li v-for="blocker in sanad.blockers" :key="blocker">{{ blocker }}</li></ul>
                    </Alert>

                    <div v-if="sanad.mode" class="flex flex-col gap-3" data-testid="outbox-state" :data-status="sanad.outbox?.status ?? sanad.delivery.status" :data-delivery="sanad.delivery.status">
                        <div class="flex flex-wrap items-center gap-3">
                            <StatusBadge kind="sanad_delivery_status" :status="sanad.delivery.status" data-testid="delivery-status" />
                            <span class="text-label text-ink-3">{{ t(`production.show.delivery.mode_${sanad.mode}`) }}</span>
                            <span v-if="sanad.delivery.acked_at" class="text-label text-ink-3">{{ when(sanad.delivery.acked_at) }}</span>
                            <span v-if="sanad.delivery.failures" class="text-label text-ink-2" data-testid="delivery-attempts">{{ t('production.show.delivery.attempts') }}: <Num :value="String(sanad.delivery.failures)" /> <template v-if="sanad.mode === 'outbox'">{{ t('production.show.delivery.of') }} <Num :value="String(sanad.delivery.max_failures)" /></template></span>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span v-if="sanad.delivery.result?.consume_status" class="text-label text-ink-2">{{ t('production.show.consume_status') }}: {{ sanad.delivery.result.consume_status }}</span>
                            <span v-if="sanad.delivery.result?.receive_status" class="text-label text-ink-2">{{ t('production.show.receive_status') }}: {{ sanad.delivery.result.receive_status }}</span>
                            <span v-if="sanad.sync?.consume?.total_cost" class="text-label text-ink-2">{{ t('production.show.delivery.consumed_cost') }}: <Num :value="sanad.sync.consume.total_cost"><template #suffix> {{ t('production.units.egp') }}</template></Num></span>
                            <span v-if="sanad.delivery.result?.product_batch_uuid" class="text-label text-ink-2">{{ t('production.show.delivery.batch') }}: <bdi dir="ltr">{{ sanad.delivery.result.product_batch_uuid }}</bdi></span>
                        </div>
                        <Alert v-if="sanad.delivery.needs_attention" :tone="sanad.delivery.status === 'conflict' ? 'warning' : 'danger'" icon="ph:warning" data-testid="delivery-alert">
                            <p v-if="sanad.delivery.status === 'needs_review'">{{ t('production.show.delivery.needs_review') }}</p>
                            <p v-else-if="sanad.delivery.status === 'partial'">{{ t('production.show.delivery.partial') }}</p>
                            <p v-if="sanad.delivery.error?.message">{{ sanad.delivery.error.remote_message ?? sanad.delivery.error.message }}</p>
                            <ul v-if="sanad.delivery.error?.shortfalls?.length" class="list-disc ps-5" :aria-label="t('production.show.delivery.shortfalls')">
                                <li v-for="row in sanad.delivery.error.shortfalls" :key="row.product_uuid"><bdi dir="ltr">{{ row.product_uuid }}</bdi>: <Num :value="row.required" /> / <Num :value="row.available" /></li>
                            </ul>
                            <p v-if="sanad.mode === 'lan' && can.send">{{ t('production.show.delivery.retry_hint') }}</p>
                        </Alert>
                    </div>
                    <p v-else-if="order.status === 'draft'" class="text-label text-ink-3">{{ t('production.show.not_sent_draft') }}</p>
                    <p v-else class="text-label text-ink-3">{{ t('production.show.not_sent') }}</p>
                </div>
            </Card>
        </div>

        <Modal v-model:open="starting" :title="t('production.show.start_batch')" :description="t('production.show.start_batch_description')">
            <form class="flex flex-col gap-4" novalidate @submit.prevent="startBatch">
                <FormField :label="t('production.show.operator')" :hint="t('production.show.operator_hint')" :error="batchForm.errors.operator" required>
                    <Input v-model="batchForm.operator" maxlength="255" data-testid="batch-operator" />
                </FormField>
                <DecimalField v-model="batchForm.planned_waste_pct" label-key="production.show.planned_waste" hint-key="production.show.planned_waste_hint" suffix="%" :error="batchForm.errors.planned_waste_pct" />
                <div class="flex flex-wrap gap-2">
                    <Button type="submit" :loading="batchForm.processing" icon="ph:play" data-testid="batch-submit">{{ t('production.show.start_batch') }}</Button>
                    <Button variant="ghost" @click="starting = false">{{ t('common.cancel') }}</Button>
                </div>
            </form>
        </Modal>
    </div>
</template>
