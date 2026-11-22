<script setup>
/**
 * The top of both price list tabs (products, merchants): the list's name, where it stands with Sanad, the on/off switch,
 * the publish action and what needs attention (changed since the last publish, Sanad's failure or conflict). Shared so the two
 * tabs never drift apart.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Alert, Badge, Button, PageHeader, Switch, TabNav } from '@kit';
import StatusBadge from '../StatusBadge.vue';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import { path } from '../../lib/routes';

const props = defineProps({
    /** { id, name, currency, is_active, sync_status, sync_detail, published_at, changed_since_publish, items_count, customers_count } */
    priceList: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();

const tabs = computed(() => [
    { label: t('price_lists.tabs.products'), href: path('price-lists.show', props.priceList.id), count: props.priceList.items_count },
    { label: t('price_lists.tabs.merchants'), href: path('price-lists.merchants', props.priceList.id), count: props.priceList.customers_count },
]);

const failure = computed(() => props.priceList.sync_detail?.error ?? null);
const conflictReason = computed(() => props.priceList.sync_detail?.reason ?? null);

function setActive(value) {
    router.put(path('price-lists.update', props.priceList.id), { is_active: value }, { preserveScroll: true });
}

function publish() {
    router.post(path('price-lists.publish', props.priceList.id), {}, { preserveScroll: true });
}
</script>

<template>
    <PageHeader :title="priceList.name" :description="t('price_lists.show.description')" :back="path('price-lists.index')" :back-label="t('price_lists.show.back')">
        <template #actions>
            <Switch :model-value="priceList.is_active" :label="t('price_lists.show.active')" data-testid="list-active" @update:model-value="setActive" />
            <Button icon="ph:paper-plane-tilt" data-testid="publish" @click="publish">{{ t('price_lists.show.publish') }}</Button>
        </template>
        <template #meta>
            <StatusBadge kind="price_list_sync_status" :status="priceList.sync_status" data-testid="sync-status" />
            <Badge tone="neutral" mono>{{ priceList.currency }}</Badge>
            <span v-if="priceList.published_at" class="text-label text-ink-3" data-testid="published-at">{{ t('price_lists.show.published_at', { time: when(priceList.published_at) }) }}</span>
            <span v-else class="text-label text-ink-3">{{ t('price_lists.show.never_published') }}</span>
        </template>
        <template #tabs><TabNav :items="tabs" :aria-label="t('price_lists.tabs.label')" /></template>
    </PageHeader>

    <div class="mb-4 flex flex-col gap-3">
        <Alert v-if="priceList.changed_since_publish" tone="info" icon="ph:arrows-clockwise" data-testid="changed-since-publish">{{ t('price_lists.show.changed_since_publish') }}</Alert>
        <Alert v-if="priceList.sync_status === 'failed'" tone="danger" icon="ph:x-circle" :title="t('price_lists.show.failed_title')" data-testid="sync-failed">
            {{ failure?.message ?? t('price_lists.show.failed_unknown') }}
            <span v-if="failure?.code" class="ms-2"><Badge tone="danger" mono>{{ failure.code }}</Badge></span>
            <p v-if="priceList.sync_detail?.requeued" class="mt-1 text-label">{{ t('price_lists.show.failed_requeued', { count: priceList.sync_detail.failures }) }}</p>
        </Alert>
        <Alert v-if="priceList.sync_status === 'conflict'" tone="warning" icon="ph:warning" :title="t('price_lists.show.conflict_title')" data-testid="sync-conflict">
            {{ t('price_lists.show.conflict_description') }}
            <span v-if="conflictReason" class="ms-2"><Badge tone="warning" mono>{{ conflictReason }}</Badge></span>
        </Alert>
    </div>
</template>
