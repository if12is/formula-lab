<script setup>
/**
 * Price lists: a named set of per-product prices for some merchants, published to Sanad. Search, filters (Sanad state,
 * on/off, date), sort and paging live in the URL (ListFilters, ListQuery). A new list is a name; its products, prices and
 * merchants are set on its own page.
 */
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Badge, Button, DataTable, EmptyState, FormField, Input, Modal, PageHeader, Pagination } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import Num from '../../components/Num.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';
import { path } from '../../lib/routes';

const props = defineProps({
    list: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const query = useListQuery(props.list.schema);
const creating = ref(false);
const form = useForm({ name: '' });

const columns = computed(() => [
    { key: 'name', label: t('price_lists.columns.name'), sortable: true },
    { key: 'status', label: t('price_lists.columns.status') },
    { key: 'items', label: t('price_lists.columns.items'), sortable: true, align: 'end', hideBelow: 'sm' },
    { key: 'merchants', label: t('price_lists.columns.merchants'), sortable: true, align: 'end', hideBelow: 'sm' },
    { key: 'published', label: t('price_lists.columns.published'), hideBelow: 'lg' },
    { key: 'updated', label: t('price_lists.columns.updated'), sortable: true, hideBelow: 'md' },
]);
const filtered = computed(() => query.activeCount.value > 0);

function openCreate() {
    form.reset();
    form.clearErrors();
    creating.value = true;
}

function create() {
    form.post(path('price-lists.store'), { onSuccess: () => { creating.value = false; } });
}
</script>

<template>
    <Head :title="t('price_lists.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('price_lists.title')" :description="t('price_lists.description')">
            <template #actions>
                <Button icon="ph:plus" data-testid="new-list" @click="openCreate">{{ t('price_lists.new') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['name', 'status']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('price_lists.title')"
                :count="list.meta.total"
                :row-href="(row) => row.href"
            >
                <template #cell-name="{ row }">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-ink" :data-list="row.name">{{ row.name }}</span>
                        <Badge v-if="!row.is_active" tone="neutral" size="sm">{{ t('price_lists.active.off') }}</Badge>
                    </span>
                </template>
                <template #cell-status="{ row }"><StatusBadge kind="price_list_sync_status" :status="row.sync_status" /></template>
                <template #cell-items="{ row }"><Num :value="row.items_count" :decimals="0" /></template>
                <template #cell-merchants="{ row }"><Num :value="row.customers_count" :decimals="0" /></template>
                <template #cell-published="{ row }"><span v-if="row.published_at" class="whitespace-nowrap tnum">{{ when(row.published_at) }}</span><span v-else class="text-ink-3">{{ t('price_lists.show.never_published') }}</span></template>
                <template #cell-updated="{ row }"><bdi dir="ltr" class="tnum">{{ row.updated_at }}</bdi></template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:tag'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('price_lists.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('price_lists.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:plus" @click="openCreate">{{ t('price_lists.new') }}</Button>
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

        <Modal v-model:open="creating" :title="t('price_lists.form.title')" :description="t('price_lists.form.description')" size="sm">
            <form id="price-list-form" novalidate @submit.prevent="create">
                <FormField :label="t('price_lists.form.name')" :hint="t('price_lists.form.name_hint')" :error="form.errors.name" required>
                    <Input v-model="form.name" data-testid="list-name" />
                </FormField>
            </form>
            <template #footer>
                <Button variant="ghost" @click="creating = false">{{ t('common.cancel') }}</Button>
                <Button type="submit" form="price-list-form" :loading="form.processing" data-testid="list-create">{{ t('price_lists.form.submit') }}</Button>
            </template>
        </Modal>
    </div>
</template>
