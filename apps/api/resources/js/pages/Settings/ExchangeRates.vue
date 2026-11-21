<script setup>
/**
 * Settings > Exchange rates: the dated rates every foreign-currency price is converted with (1 unit = x EGP, EGP is the
 * fixed base). The latest rate per currency up top, then the full list with search, filters (currency, date range,
 * source, confidence), sort and paging kept in the URL (ListFilters, useListQuery, ListQuery on the server).
 * Adding and editing are a modal. Nothing is pre-filled or defaulted: a currency without a rate says so.
 */
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DataTable, EmptyState, IconButton, PageHeader, Pagination } from '@kit';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import ExchangeRateModal from '../../components/settings/ExchangeRateModal.vue';
import ListFilters from '../../components/list/ListFilters.vue';
import Num from '../../components/Num.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
    latest: { type: Array, required: true },
    prefill: { type: Object, default: null },
    currencyOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);
const modalOpen = ref(false);
const editing = ref(null);
const modalPrefill = ref(null);

const columns = computed(() => [
    { key: 'currency', label: t('exchange_rates.columns.currency'), sortable: true },
    { key: 'rate', label: t('exchange_rates.columns.rate'), sortable: true, align: 'end' },
    { key: 'date', label: t('exchange_rates.columns.date'), sortable: true },
    { key: 'source', label: t('exchange_rates.columns.source'), hideBelow: 'md' },
    { key: 'confidence', label: t('exchange_rates.columns.confidence'), hideBelow: 'sm' },
    { key: 'actions', label: t('exchange_rates.columns.actions'), align: 'end' },
]);

const filtered = computed(() => query.activeCount.value > 0);
const baseCurrency = 'EGP';

function add(prefill = null) {
    editing.value = null;
    modalPrefill.value = prefill;
    modalOpen.value = true;
}

function edit(row) {
    editing.value = row;
    modalPrefill.value = null;
    modalOpen.value = true;
}

// A link from a price without a rate for its date opens the form already pointed at that currency and day.
onMounted(() => {
    if (props.prefill) {
        add(props.prefill);
    }
});
</script>

<template>
    <Head :title="t('exchange_rates.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('exchange_rates.title')" :description="t('exchange_rates.description')">
            <template #actions>
                <Button icon="ph:plus" data-testid="add-rate" @click="add()">{{ t('exchange_rates.add') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Alert tone="info" icon="ph:currency-circle-dollar">{{ t('exchange_rates.base_note', { currency: t(`currencies.${baseCurrency}.name`) }) }}</Alert>

            <Card :title="t('exchange_rates.latest.title')" :description="t('exchange_rates.latest.description')">
                <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="latest-rates">
                    <li v-for="entry in latest" :key="entry.currency" class="flex flex-col gap-1 rounded-lg border border-line p-3" :data-latest="entry.currency">
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-ink">{{ t(`currencies.${entry.currency}.name`) }}</span>
                            <Badge tone="neutral" mono>{{ entry.currency }}</Badge>
                        </span>
                        <template v-if="entry.rate">
                            <span class="type-metric text-ink">
                                <span class="text-label font-normal text-ink-3">{{ t('exchange_rates.unit', { currency: entry.currency }) }}</span>
                                <Num :value="entry.rate.rate" /> <span class="text-label font-normal text-ink-3">{{ t(`currencies.${baseCurrency}.symbol`) }}</span>
                            </span>
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-label text-ink-3">
                                <span>{{ t('exchange_rates.latest.as_of', { date: entry.rate.rate_date }) }}</span>
                                <span>{{ entry.rate.source_title }}</span>
                                <ConfidenceBadge :confidence="entry.rate.confidence" />
                            </span>
                        </template>
                        <template v-else>
                            <span class="flex items-center gap-2 text-ink-3">
                                <Num :value="null" reason-key="exchange_rates.latest.none" />
                                <span class="text-label">{{ t('exchange_rates.latest.none') }}</span>
                            </span>
                            <span><Button variant="link" size="sm" icon="ph:plus" @click="add({ currency: entry.currency, rate_date: null })">{{ t('exchange_rates.latest.add_for') }}</Button></span>
                        </template>
                    </li>
                </ul>
            </Card>

            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['currency', 'rate', 'date']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('exchange_rates.title')"
                :count="list.meta.total"
            >
                <template #cell-currency="{ row }">
                    <span class="flex flex-col">
                        <bdi dir="ltr" class="font-semibold text-ink">{{ row.currency }}</bdi>
                        <span class="text-label text-ink-3">{{ t(`currencies.${row.currency}.name`) }}</span>
                    </span>
                </template>
                <template #cell-rate="{ row }">
                    <span class="whitespace-nowrap"><span class="text-label text-ink-3">{{ t('exchange_rates.unit', { currency: row.currency }) }}</span> <Num :value="row.rate" /> <span class="text-label text-ink-3">{{ t(`currencies.${baseCurrency}.symbol`) }}</span></span>
                </template>
                <template #cell-date="{ row }"><bdi dir="ltr" class="tnum">{{ row.rate_date }}</bdi></template>
                <template #cell-source="{ row }">{{ row.source_title }}</template>
                <template #cell-confidence="{ row }"><ConfidenceBadge :confidence="row.confidence" /></template>
                <template #cell-actions="{ row }">
                    <IconButton icon="ph:pencil-simple" variant="ghost" size="sm" :label="t('exchange_rates.edit_label')" @click="edit(row)" />
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:currency-circle-dollar'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('exchange_rates.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('exchange_rates.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:plus" @click="add()">{{ t('exchange_rates.add') }}</Button>
                        </template>
                    </EmptyState>
                </template>
            </DataTable>

            <Pagination
                v-model:page="query.page.value"
                v-model:per-page="query.per.value"
                :total="list.meta.total"
                :per-page-options="list.schema.perPageOptions"
            />
        </div>

        <ExchangeRateModal
            v-model:open="modalOpen"
            :row="editing"
            :prefill="modalPrefill"
            :currency-options="currencyOptions"
            :confidence-options="confidenceOptions"
            :source-kind-options="sourceKindOptions"
            :urls="urls"
        />
    </div>
</template>
