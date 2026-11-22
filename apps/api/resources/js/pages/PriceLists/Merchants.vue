<script setup>
/**
 * One price list, merchants tab. The merchants are Sanad customers: they live in Sanad and are only read here (a searchable
 * selector over the synced customers feed), never created or edited. Each row says where Sanad itself has the customer
 * today (on this list, on another list, or on none), because an assignment only reaches Sanad when the list is published.
 * Search, filters, sort and paging live in the URL (ListFilters, ListQuery).
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Alert, Badge, Button, DataTable, EmptyState, IconButton, Pagination, useConfirm } from '@kit';
import EntitySelect from '../../components/form/EntitySelect.vue';
import ListFilters from '../../components/list/ListFilters.vue';
import PriceListHeader from '../../components/pricing/PriceListHeader.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';
import { path } from '../../lib/routes';

const props = defineProps({
    priceList: { type: Object, required: true },
    list: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { confirm } = useConfirm();
const query = useListQuery(props.list.schema);
const customer = ref(null);
const errors = ref({});
const adding = ref(false);

const columns = computed(() => [
    { key: 'name', label: t('price_lists.customers.columns.name'), sortable: true },
    { key: 'code', label: t('price_lists.customers.columns.code'), sortable: true, hideBelow: 'sm' },
    { key: 'sanad', label: t('price_lists.customers.columns.sanad') },
    { key: 'flags', label: t('price_lists.customers.columns.flags'), hideBelow: 'md' },
]);
const filtered = computed(() => query.activeCount.value > 0);
const SANAD_TONES = { this: 'success', other: 'warning', none: 'neutral' };

function add() {
    if (!customer.value) {
        return;
    }

    router.post(path('price-lists.customers.store', props.priceList.id), { customer_uuid: customer.value }, {
        preserveScroll: true,
        onStart: () => { adding.value = true; errors.value = {}; },
        onError: (bag) => { errors.value = bag; },
        onSuccess: () => { customer.value = null; },
        onFinish: () => { adding.value = false; },
    });
}

async function remove(row) {
    const confirmed = await confirm({
        title: t('price_lists.customers.remove_title'),
        description: t('price_lists.customers.remove_description', { customer: row.name }),
        confirmLabel: t('price_lists.customers.remove'),
        tone: 'danger',
    });

    if (confirmed) {
        router.delete(path('price-lists.customers.destroy', { priceList: props.priceList.id, customer: row.id }), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="priceList.name" />
    <div class="kit-page is-full">
        <PriceListHeader :price-list="priceList" />

        <div class="flex flex-col gap-4">
            <Alert tone="info" icon="ph:storefront">{{ t('price_lists.customers.note') }}</Alert>

            <div class="flex flex-wrap items-end gap-3" data-testid="add-customer">
                <div class="min-w-0 flex-1 basis-72">
                    <EntitySelect v-model="customer" label-key="price_lists.customers.select" hint-key="price_lists.customers.select_hint" :search-url="urls.customers" :error="errors.customer_uuid" />
                </div>
                <Button icon="ph:plus" :disabled="!customer" :loading="adding" data-testid="assign-customer" @click="add">{{ t('price_lists.customers.add') }}</Button>
            </div>

            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['name', 'sanad']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('price_lists.tabs.merchants')"
                :count="list.meta.total"
            >
                <template #cell-name="{ row }">
                    <span class="flex flex-col" :data-customer="row.name">
                        <span class="font-semibold text-ink">{{ row.name }}</span>
                        <span v-if="row.name_en" class="text-label text-ink-3"><bdi dir="ltr">{{ row.name_en }}</bdi></span>
                    </span>
                </template>
                <template #cell-code="{ row }"><bdi dir="ltr" class="tnum">{{ row.code ?? t('common.no_data') }}</bdi></template>
                <template #cell-sanad="{ row }"><Badge :tone="SANAD_TONES[row.in_sanad]" dot :data-in-sanad="row.in_sanad">{{ t(`price_lists.customers.in_sanad.${row.in_sanad}`) }}</Badge></template>
                <template #cell-flags="{ row }">
                    <span class="flex flex-wrap gap-1">
                        <Badge v-if="row.wholesale" tone="info" size="sm">{{ t('price_lists.customers.wholesale.yes') }}</Badge>
                        <Badge v-if="row.deleted" tone="danger" size="sm">{{ t('price_lists.customers.archived') }}</Badge>
                        <Badge v-else-if="!row.active" tone="warning" size="sm">{{ t('price_lists.customers.inactive') }}</Badge>
                    </span>
                </template>
                <template #row-actions="{ row }">
                    <IconButton icon="ph:trash" variant="ghost" size="sm" :label="t('price_lists.customers.remove_label', { customer: row.name })" :data-testid="`remove-customer-${row.name}`" @click="remove(row)" />
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:storefront'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('price_lists.customers.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('price_lists.customers.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
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
    </div>
</template>
