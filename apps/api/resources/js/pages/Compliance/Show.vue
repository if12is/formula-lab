<script setup>
/**
 * One rule set: its facts, the engine's validation of it as it would be published, and its rules (searchable, filterable,
 * sortable, state in the address). A draft is edited here: rules are added, changed and removed with plain fields, and
 * publishing freezes it with its hash. A published rule set can only be changed by making a new draft version of it.
 * A rule with no source yet (`pendingSource`) is allowed in a draft only and keeps the rule set from being published.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Badge, Button, Card, CopyButton, DataTable, DescriptionList, EmptyState, FormField, IconButton, Input, Modal, PageHeader, Pagination, Tooltip, useConfirm } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import PublishModal from '../../components/compliance/PublishModal.vue';
import RuleFormModal from '../../components/compliance/RuleFormModal.vue';
import RuleSetFormModal from '../../components/compliance/RuleSetFormModal.vue';
import Num from '../../components/Num.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';
import { publishPreview } from '../../lib/compliance';

const props = defineProps({
    ruleSet: { type: Object, required: true },
    list: { type: Object, required: true },
    assembled: { type: Object, required: true },
    versions: { type: Array, required: true },
    urls: { type: Object, required: true },
    sourceKindOptions: { type: Array, required: true },
    ruleDefaults: { type: Object, required: true },
});

const { t, has, locale } = useI18n();
const { confirm } = useConfirm();
const query = useListQuery(props.list.schema);

const draft = computed(() => props.ruleSet.status === 'draft');
const editingSet = ref(false);
const publishing = ref(false);
const versioning = ref(false);
const ruleModal = ref(false);
const editedRow = ref(null);

// What the engine says about the whole rule set as it would be published (the same validator the server runs).
const preview = computed(() => publishPreview(props.assembled));
const pendingRules = computed(() => props.assembled.rules.filter((rule) => rule.pendingSource).length);

const columns = computed(() => [
    { key: 'rule_key', label: t('compliance.rules.columns.rule'), sortable: true },
    { key: 'scope', label: t('compliance.rules.columns.scope'), hideBelow: 'lg' },
    { key: 'target', label: t('compliance.rules.columns.target'), hideBelow: 'md' },
    { key: 'operator', label: t('compliance.rules.columns.operator'), sortable: true },
    { key: 'limit', label: t('compliance.rules.columns.limit') },
    { key: 'group', label: t('compliance.rules.columns.group'), hideBelow: 'lg' },
    { key: 'source', label: t('compliance.rules.columns.source'), hideBelow: 'md' },
    ...(draft.value ? [{ key: 'actions', label: t('compliance.rules.columns.actions'), align: 'end' }] : []),
]);

const filtered = computed(() => query.activeCount.value > 0);
const messageOf = (row) => row.message ?? (row.message_key && has(`engine.${row.message_key}`) ? t(`engine.${row.message_key}`) : t('common.no_data'));
const when = (iso) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short', numberingSystem: 'latn' }).format(new Date(iso));

const facts = computed(() => [
    { term: t('compliance.facts.code'), value: props.ruleSet.code, mono: true },
    { term: t('compliance.facts.version'), value: props.ruleSet.version, mono: true },
    { term: t('compliance.facts.source'), key: 'source' },
    { term: t('compliance.facts.rules'), value: t('compliance.facts.rules_value', { active: props.ruleSet.active_rules_count, total: props.ruleSet.rules_count }) },
    { term: t('compliance.facts.published_at'), value: props.ruleSet.published_at ? when(props.ruleSet.published_at) : t('common.no_data') },
    { term: t('compliance.facts.hash'), key: 'hash' },
]);

function addRule() {
    editedRow.value = null;
    ruleModal.value = true;
}

function editRule(row) {
    editedRow.value = row;
    ruleModal.value = true;
}

async function removeRule(row) {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('compliance.rules.remove_title'),
        description: t('compliance.rules.remove_description', { rule: row.rule_key }),
        confirmLabel: t('compliance.rules.remove'),
    });

    if (confirmed) {
        router.delete(path('compliance.rules.destroy', [props.ruleSet.id, row.id]), { preserveScroll: true });
    }
}

async function removeRuleSet() {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('compliance.delete_title'),
        description: t('compliance.delete_description', { code: props.ruleSet.code, version: props.ruleSet.version }),
        confirmLabel: t('compliance.delete'),
    });

    if (confirmed) {
        router.delete(path('compliance.destroy', props.ruleSet.id));
    }
}

const versionForm = useForm({ version: '' });

function createVersion() {
    versionForm.post(path('compliance.versions.store', props.ruleSet.id), { onSuccess: () => { versioning.value = false; } });
}
</script>

<template>
    <Head :title="`${ruleSet.code} · ${ruleSet.name}`" />
    <div class="kit-page">
        <PageHeader :title="ruleSet.name" :back="path('compliance.index')" :back-label="t('compliance.title')">
            <template #meta>
                <span class="flex flex-wrap items-center gap-2">
                    <bdi dir="ltr" class="font-semibold text-ink-2">{{ ruleSet.code }}</bdi>
                    <Badge tone="neutral" mono>{{ ruleSet.version }}</Badge>
                    <StatusBadge kind="rule_set_status" :status="ruleSet.status" />
                </span>
            </template>
            <template #actions>
                <template v-if="draft">
                    <Button variant="secondary" icon="ph:pencil-simple" @click="editingSet = true">{{ t('compliance.edit') }}</Button>
                    <Button variant="secondary" icon="ph:plus" data-testid="add-rule" @click="addRule">{{ t('compliance.rules.add') }}</Button>
                    <Button icon="ph:seal-check" data-testid="publish" @click="publishing = true">{{ t('compliance.publish.action') }}</Button>
                </template>
                <Button v-else icon="ph:git-branch" data-testid="new-version" @click="versioning = true">{{ t('compliance.new_version') }}</Button>
            </template>
        </PageHeader>

        <Alert v-if="!draft" tone="info" icon="ph:lock-simple" class="mb-4" :title="t('compliance.published_title')">{{ t('compliance.published_description') }}</Alert>
        <Alert v-else-if="pendingRules" tone="warning" icon="ph:warning" class="mb-4" :title="t('compliance.pending_title')" data-testid="pending-source-alert">
            {{ t('compliance.pending_description', { count: pendingRules }) }}
        </Alert>

        <Card class="mb-4">
            <DescriptionList :items="facts" :columns="3">
                <template #source>
                    <span v-if="ruleSet.source">{{ ruleSet.source.title }}</span>
                    <span v-else class="text-ink-3">{{ t('compliance.facts.no_source') }}</span>
                </template>
                <template #hash>
                    <span v-if="ruleSet.hash" class="flex items-center gap-1">
                        <bdi dir="ltr" class="min-w-0 break-all font-mono text-label" data-testid="rule-set-hash">{{ ruleSet.hash }}</bdi>
                        <CopyButton :value="ruleSet.hash" :label="t('compliance.publish.copy_hash')" />
                    </span>
                    <span v-else class="text-ink-3">{{ t('compliance.facts.hash_draft') }}</span>
                </template>
            </DescriptionList>
            <p v-if="ruleSet.scope_notes" class="mt-4 max-w-prose whitespace-pre-line text-label text-ink-2">{{ ruleSet.scope_notes }}</p>
        </Card>

        <Card v-if="draft" :title="t('compliance.validation.title')" :description="t('compliance.validation.description')" class="mb-4" data-testid="validation-card">
            <Alert v-if="assembled.rules.length === 0" tone="warning" icon="ph:info">{{ t('compliance.validation.no_rules') }}</Alert>
            <Alert v-else-if="preview.valid" tone="success" icon="ph:check-circle" data-testid="validation-ok">{{ t('compliance.validation.ready') }}</Alert>
            <Alert v-else tone="warning" icon="ph:warning" :title="t('compliance.validation.issues_title', { count: preview.issues.length })" data-testid="validation-issues">
                <ul class="list-disc ps-5">
                    <li v-for="issue in preview.issues" :key="`${issue.property}-${issue.reason}`">
                        {{ t(`engine.${issue.reason}`) }} <bdi dir="ltr" class="font-mono text-label">{{ issue.property }}</bdi>
                    </li>
                </ul>
            </Alert>
        </Card>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['rule_key', 'operator', 'limit']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('compliance.rules.title')"
                :count="list.meta.total"
                :row-tone="(row) => (row.pending_source ? 'warning' : null)"
            >
                <template #cell-rule_key="{ row }">
                    <span class="flex flex-col gap-0.5" :data-rule="row.rule_key">
                        <span class="flex flex-wrap items-center gap-2">
                            <bdi dir="ltr" class="font-semibold text-ink">{{ row.rule_key }}</bdi>
                            <Badge v-if="!row.active" tone="neutral" size="sm">{{ t('compliance.rules.inactive') }}</Badge>
                        </span>
                        <span class="text-label text-ink-3">{{ messageOf(row) }}</span>
                    </span>
                </template>
                <template #cell-scope="{ row }">
                    <span class="flex flex-wrap gap-1">
                        <Badge v-for="type in row.product_types" :key="type" tone="neutral" size="sm">{{ type }}</Badge>
                        <Badge v-if="row.rinse_off !== null" tone="info" size="sm">{{ t(row.rinse_off ? 'compliance.rules.scope_rinse_off' : 'compliance.rules.scope_leave_on') }}</Badge>
                        <Badge v-if="row.ifra_category" tone="info" size="sm"><bdi dir="ltr">{{ t('compliance.rules.scope_ifra', { category: row.ifra_category }) }}</bdi></Badge>
                    </span>
                </template>
                <template #cell-target="{ row }">
                    <span class="flex flex-col">
                        <span class="text-label text-ink-3">{{ t(`enums.rule_target_type.${row.target_type}`) }}</span>
                        <bdi dir="ltr" class="font-mono text-label">{{ row.target_value }}</bdi>
                    </span>
                </template>
                <template #cell-operator="{ row }">{{ t(`enums.rule_operator.${row.operator}`) }}</template>
                <template #cell-limit="{ row }">
                    <span v-if="row.limit !== null" class="whitespace-nowrap"><Num :value="row.limit" /> <bdi dir="ltr" class="text-label text-ink-3">{{ row.unit }}</bdi></span>
                    <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                </template>
                <template #cell-group="{ row }">
                    <bdi v-if="row.group" dir="ltr" class="font-mono text-label">{{ row.group }}</bdi>
                    <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                </template>
                <template #cell-source="{ row }">
                    <span v-if="row.source" class="flex flex-col">
                        <span>{{ row.source.title }}</span>
                        <span class="text-label text-ink-3">{{ row.source.ref }}</span>
                    </span>
                    <Tooltip v-else :content="t('compliance.rules.pending_source_hint')">
                        <Badge tone="warning" icon="ph:warning" tabindex="0" data-testid="pending-source-badge">{{ t('compliance.rules.pending_source') }}</Badge>
                    </Tooltip>
                </template>
                <template #cell-actions="{ row }">
                    <span class="flex justify-end gap-1">
                        <IconButton icon="ph:pencil-simple" size="sm" variant="secondary" :label="t('compliance.rules.edit_label', { rule: row.rule_key })" @click="editRule(row)" />
                        <IconButton icon="ph:trash" size="sm" variant="danger-soft" :label="t('compliance.rules.remove_label', { rule: row.rule_key })" @click="removeRule(row)" />
                    </span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:list-checks'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('compliance.rules.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('compliance.rules.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else-if="draft" icon="ph:plus" @click="addRule">{{ t('compliance.rules.add') }}</Button>
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

        <Card v-if="versions.length > 1" :title="t('compliance.versions.title')" class="mt-4">
            <ul class="flex flex-col divide-y divide-line">
                <li v-for="entry in versions" :key="entry.id" class="flex flex-wrap items-center gap-3 py-2" :class="entry.current && 'font-semibold'">
                    <Badge tone="neutral" mono>{{ entry.version }}</Badge>
                    <StatusBadge kind="rule_set_status" :status="entry.status" />
                    <Button v-if="!entry.current" :href="entry.href" variant="link" size="sm">{{ t('compliance.versions.open') }}</Button>
                </li>
            </ul>
        </Card>

        <RuleSetFormModal v-model:open="editingSet" :rule-set="ruleSet" :urls="urls" :source-kind-options="sourceKindOptions" />
        <RuleFormModal v-model:open="ruleModal" :rule-set-id="ruleSet.id" :version="ruleSet.version" :row="editedRow" :defaults="ruleDefaults" :urls="urls" :source-kind-options="sourceKindOptions" />
        <PublishModal v-model:open="publishing" :rule-set="ruleSet" :assembled="assembled" />

        <Modal v-model:open="versioning" :title="t('compliance.new_version_title')" :description="t('compliance.new_version_description', { code: ruleSet.code, version: ruleSet.version })" size="sm">
            <form id="version-form" novalidate @submit.prevent="createVersion">
                <FormField :label="t('compliance.form.version')" :hint="t('compliance.form.version_hint')" :error="versionForm.errors.version" required>
                    <Input v-model="versionForm.version" ltr maxlength="50" />
                </FormField>
            </form>
            <template #footer>
                <Button variant="ghost" @click="versioning = false">{{ t('common.cancel') }}</Button>
                <Button type="submit" form="version-form" :loading="versionForm.processing" icon="ph:git-branch">{{ t('compliance.new_version_submit') }}</Button>
            </template>
        </Modal>

    </div>
</template>
