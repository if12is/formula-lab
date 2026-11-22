<script setup>
/**
 * Publishing a formula's finished good to Sanad (G-09). The button works only when the owner-approved publish rules pass (an
 * approved cost still in force, the price inputs complete, no material price moved past the limit); otherwise the page says which
 * rule fails and what to do. The cost price sent is the approved unit cost, shown here, never typed. The rules and their answer are
 * the engine's; this page only shows them (through the shared DecisionCard, which also offers the signed override of a blocked decision). Wholesale prices per merchant live in price lists, linked below.
 */
import { computed, onBeforeUnmount, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, EmptyState, FormField, Input, PageHeader } from '@kit';
import DecisionCard from '../../components/decisions/DecisionCard.vue';
import DecimalField from '../../components/form/DecimalField.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import Num from '../../components/Num.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';
import { path } from '../../lib/routes';

const props = defineProps({
    formula: { type: Object, required: true },
    details: { type: Object, required: true },
    approval: { type: Object, default: null },
    inForce: { type: Boolean, required: true },
    tax: { type: Object, required: true },
    decision: { type: Object, required: true },
    override: { type: Object, required: true },
    priceLists: { type: Array, required: true },
    history: { type: Array, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const text = (value) => value ?? '';
const form = useForm({
    sku: text(props.details.sku),
    barcode: text(props.details.barcode),
    uom: text(props.details.uom),
    sell_price: text(props.details.sell_price),
    wholesale_price: text(props.details.wholesale_price),
    guide_price: text(props.details.guide_price),
    name_en: text(props.details.name_en),
});

const columns = computed(() => [
    { key: 'published_at', label: t('sanad_publish.history.when') },
    { key: 'mode', label: t('sanad_publish.history.mode'), hideBelow: 'md' },
    { key: 'sku', label: t('sanad_publish.history.sku') },
    { key: 'cost_price', label: t('sanad_publish.history.cost'), hideBelow: 'md' },
    { key: 'sell_price', label: t('sanad_publish.history.sell') },
]);
// An override signed for exactly this decision lets it go ahead; the server checks it again.
const canPublish = computed(() => props.decision.state === 'allowed' || (props.decision.state === 'blocked' && props.override.active !== null));
const published = computed(() => props.formula.sanad_product_uuid !== null);

// The decision judges the selling price typed here, so it is asked again (the server decides, nothing is judged in the browser) a moment after typing stops.
let askTimer = null;
watch(() => form.sell_price, (value) => {
    clearTimeout(askTimer);
    askTimer = setTimeout(() => {
        router.get(path('formulas.publish', props.formula.id), { sell_price: normalizeDecimalText(value) }, { only: ['decision'], preserveState: true, preserveScroll: true, replace: true });
    }, 400);
});
onBeforeUnmount(() => clearTimeout(askTimer));

function publish() {
    form
        .transform((data) => ({ ...data, sell_price: normalizeDecimalText(data.sell_price), wholesale_price: normalizeDecimalText(data.wholesale_price), guide_price: normalizeDecimalText(data.guide_price) }))
        .post(path('formulas.publish.store', props.formula.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${t('sanad_publish.title')} · ${formula.code}`" />
    <div class="kit-page" data-testid="publish-page">
        <PageHeader :title="t('sanad_publish.title')" :description="`${formula.code} · ${formula.name}`">
            <template #actions>
                <Button :href="path('formulas.cost-report', formula.id)" variant="secondary" icon="ph:receipt" data-testid="to-report">{{ t('sanad_publish.actions.open_report') }}</Button>
            </template>
        </PageHeader>
        <p class="mb-4 text-label text-ink-3">{{ t('sanad_publish.description') }}</p>

        <div class="flex flex-col gap-4">
            <Card :title="t('sanad_publish.cost.title')" :description="t('sanad_publish.cost.description')" data-testid="publish-cost">
                <div class="flex flex-col gap-3">
                    <Alert v-if="approval === null" tone="warning" icon="ph:warning" data-testid="cost-none">{{ t('sanad_publish.cost.none') }}</Alert>
                    <template v-else>
                        <div class="flex flex-wrap items-baseline gap-3">
                            <span class="type-metric text-ink" data-testid="approved-cost"><Num :value="approval.unit_cost" :decimals="2" /></span>
                            <span class="text-label text-ink-3">{{ t('sanad_publish.cost.unit') }}</span>
                            <ConfidenceBadge :confidence="approval.confidence" />
                            <Badge :tone="inForce ? 'success' : 'warning'" dot data-testid="cost-state" :data-state="inForce ? 'in_force' : approval.stale_reason">{{ inForce ? t('cost_report.approval.in_force') : t(`sanad_publish.cost.stale.${approval.stale_reason}`) }}</Badge>
                        </div>
                        <DescriptionList
                            :items="[
                                { term: t('sanad_publish.cost.version'), value: String(approval.version), mono: true },
                                { term: t('sanad_publish.cost.approved_by'), value: approval.approved_by ?? t('common.no_data') },
                                { term: t('sanad_publish.cost.approved_at'), value: when(approval.approved_at) },
                            ]"
                            :columns="3"
                        />
                    </template>
                    <Button v-if="!inForce" :href="path('formulas.cost-report', formula.id)" icon="ph:seal-check" class="self-start" data-testid="go-approve">{{ t('sanad_publish.actions.approve_cost') }}</Button>
                </div>
            </Card>

            <Card :title="t('sanad_publish.form.title')" :description="t('sanad_publish.form.description')" data-testid="publish-form-card">
                <form class="grid gap-4 sm:grid-cols-2" novalidate data-testid="publish-form" @submit.prevent="publish">
                    <FormField :label="t('sanad_publish.form.sku')" :error="form.errors.sku" required>
                        <Input v-model="form.sku" ltr maxlength="100" />
                    </FormField>
                    <FormField :label="t('sanad_publish.form.barcode')" :hint="t('sanad_publish.form.barcode_hint')" :error="form.errors.barcode">
                        <Input v-model="form.barcode" ltr inputmode="numeric" maxlength="64" />
                    </FormField>
                    <FormField :label="t('sanad_publish.form.uom')" :error="form.errors.uom" required>
                        <Input v-model="form.uom" maxlength="50" />
                    </FormField>
                    <FormField :label="t('sanad_publish.form.name_en')" :error="form.errors.name_en">
                        <Input v-model="form.name_en" ltr maxlength="255" />
                    </FormField>
                    <DecimalField v-model="form.sell_price" label-key="sanad_publish.form.sell_price" :suffix="t('sanad_publish.form.currency')" :error="form.errors.sell_price" required />
                    <DecimalField v-model="form.wholesale_price" label-key="sanad_publish.form.wholesale_price" hint-key="sanad_publish.form.wholesale_price_hint" :suffix="t('sanad_publish.form.currency')" :error="form.errors.wholesale_price" />
                    <DecimalField v-model="form.guide_price" label-key="sanad_publish.form.guide_price" hint-key="sanad_publish.form.guide_price_hint" :suffix="t('sanad_publish.form.currency')" :error="form.errors.guide_price" />
                    <FormField :label="t('sanad_publish.form.tax')" :hint="t('sanad_publish.form.tax_hint')">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :tone="tax.set ? 'success' : 'warning'" dot data-testid="publish-tax" :data-set="tax.set ? 'true' : 'false'">{{ tax.set ? tax.label : t('sanad_publish.form.tax_not_set') }}</Badge>
                            <Button :href="path('formulas.show', formula.id)" variant="link" size="sm" icon="ph:arrow-square-out">{{ t('sanad_publish.form.tax_open') }}</Button>
                        </div>
                    </FormField>
                </form>
            </Card>

            <DecisionCard
                :decision="decision"
                :override="override"
                :override-data="{ sell_price: normalizeDecimalText(form.sell_price) }"
                :title="t('sanad_publish.decision.title')"
                :description="t('sanad_publish.decision.description')"
                :allowed-text="t('sanad_publish.decision.allowed')"
                :blocked-text="t('sanad_publish.decision.blocked')"
                data-testid="publish-decision"
            >
                <template #extra>
                    <p v-if="approval && decision.state !== 'undecidable'" class="text-label text-ink-3">{{ t('sanad_publish.decision.max_change', { change: decision.max_change_pct, limit: decision.threshold_pct }) }}</p>
                </template>
                <template #actions>
                    <Button icon="ph:paper-plane-tilt" class="self-start" :disabled="!canPublish" :loading="form.processing" data-testid="publish-submit" @click="publish">{{ published ? t('sanad_publish.actions.publish_again') : t('sanad_publish.actions.publish') }}</Button>
                </template>
            </DecisionCard>

            <Card :title="t('sanad_publish.price_lists.title')" :description="t('sanad_publish.price_lists.description')" data-testid="publish-price-lists">
                <ul v-if="priceLists.length" class="flex flex-col gap-2">
                    <li v-for="list in priceLists" :key="list.id" class="flex flex-wrap items-center gap-2">
                        <a :href="list.href" class="text-primary-ink underline underline-offset-2">{{ list.name }}</a>
                        <StatusBadge kind="price_list_sync_status" :status="list.sync_status" />
                    </li>
                </ul>
                <p v-else class="text-ink-3">{{ t('sanad_publish.price_lists.none') }}</p>
                <Button :href="path('price-lists.index')" variant="link" size="sm" icon="ph:arrow-square-out" class="mt-2">{{ t('sanad_publish.price_lists.open') }}</Button>
            </Card>

            <Card :title="t('sanad_publish.history.title')" data-testid="publish-history">
                <EmptyState v-if="!history.length" compact icon="ph:paper-plane-tilt" :title="t('sanad_publish.history.none')" />
                <DataTable v-else :columns="columns" :rows="history" row-key="id" :mobile-columns="['published_at', 'sku', 'sell_price']" :caption="t('sanad_publish.history.title')">
                    <template #cell-published_at="{ row }">{{ when(row.published_at) }}</template>
                    <template #cell-mode="{ row }">{{ t(`sanad_publish.history.modes.${row.mode}`) }}</template>
                    <template #cell-sku="{ row }"><bdi dir="ltr">{{ row.sku }}</bdi></template>
                    <template #cell-cost_price="{ row }"><Num :value="row.cost_price" :decimals="2" /></template>
                    <template #cell-sell_price="{ row }"><Num :value="row.sell_price" :decimals="2" /></template>
                </DataTable>
            </Card>
        </div>
    </div>
</template>
