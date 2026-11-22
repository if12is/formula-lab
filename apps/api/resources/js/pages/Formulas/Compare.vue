<script setup>
/**
 * The difference between any two versions of a formula: lines added, removed and changed (share, q.s., phase), and
 * the reason and status. Both versions are picked from the formula's history; the choice lives in the URL.
 */
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Badge, Card, EmptyState, FormField, PageHeader, Select } from '@kit';
import Num from '../../components/Num.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    formula: { type: Object, required: true },
    from: { type: Number, required: true },
    to: { type: Number, required: true },
    history: { type: Array, required: true },
    diff: { type: Object, required: true },
});

const { t } = useI18n();

const versionOptions = computed(() => props.history.map((version) => ({
    value: String(version.number),
    label: t('formulas.compare.version_option', { version: version.number, status: t(`enums.formula_status.${version.status}`) }),
})));

function choose(side, value) {
    router.get(
        path('formulas.compare', props.formula.id),
        { from: props.from, to: props.to, [side]: value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const PHASES = ['water', 'oil', 'cool_down'];
// A known phase reads by its name; one typed before phases were a fixed list is shown as it was typed.
const phaseText = (phase) => (phase === null || phase === undefined ? t('common.no_data') : PHASES.includes(phase) ? t(`enums.formula_phase.${phase}`) : phase);
const metaValue = (key, value) => {
    if (value === null || value === undefined) {
        return t('common.no_data');
    }

    return key === 'status' ? t(`enums.formula_status.${value}`) : value;
};
const changeValue = (field, value) => {
    if (field === 'is_qs') {
        return value ? t('formulas.compare.qs_yes') : t('formulas.compare.qs_no');
    }

    return field === 'phase' ? phaseText(value) : value;
};
</script>

<template>
    <Head :title="t('formulas.compare.title', { code: formula.code })" />
    <div class="kit-page is-narrow">
        <PageHeader
            :title="t('formulas.compare.title', { code: formula.code })"
            :description="formula.name"
            :back="path('formulas.show', formula.id)"
            :back-label="t('formulas.compare.back')"
        />

        <Card class="mb-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField :label="t('formulas.compare.from')">
                    <Select :model-value="String(from)" :options="versionOptions" @update:model-value="(value) => choose('from', value)" />
                </FormField>
                <FormField :label="t('formulas.compare.to')">
                    <Select :model-value="String(to)" :options="versionOptions" @update:model-value="(value) => choose('to', value)" />
                </FormField>
            </div>
        </Card>

        <EmptyState
            v-if="diff.identical"
            variant="dashed"
            icon="ph:equals"
            :title="t('formulas.compare.identical_title')"
            :description="t('formulas.compare.identical_description')"
        />

        <div v-else class="flex flex-col gap-4" data-testid="diff">
            <Card v-if="Object.keys(diff.meta).length" :title="t('formulas.compare.meta')">
                <dl class="flex flex-col gap-3">
                    <div v-for="(change, key) in diff.meta" :key="key" class="flex flex-wrap items-baseline gap-2">
                        <dt class="min-w-24 text-label font-semibold text-ink-3">{{ t(`formulas.compare.meta_fields.${key}`) }}</dt>
                        <dd class="flex flex-wrap items-baseline gap-2">
                            <span class="text-ink-3 line-through">{{ metaValue(key, change.from) }}</span>
                            <span aria-hidden="true">→</span>
                            <span class="font-semibold text-ink">{{ metaValue(key, change.to) }}</span>
                        </dd>
                    </div>
                </dl>
            </Card>

            <Card :title="t('formulas.compare.added')" :description="t('formulas.compare.count', { count: diff.added.length })">
                <p v-if="!diff.added.length" class="text-label text-ink-3">{{ t('formulas.compare.none') }}</p>
                <ul v-else class="flex flex-col divide-y divide-line">
                    <li v-for="line in diff.added" :key="line.material_id" class="flex flex-wrap items-center gap-2 py-2" data-diff="added">
                        <Badge tone="success" icon="ph:plus">{{ t('formulas.compare.added_badge') }}</Badge>
                        <span class="font-semibold text-ink">{{ line.material }}</span>
                        <span v-if="line.is_qs" class="text-label text-ink-3">{{ t('formulas.qs_short') }}</span>
                        <span class="ms-auto"><Num :value="line.percent_ww" /> %</span>
                    </li>
                </ul>
            </Card>

            <Card :title="t('formulas.compare.removed')" :description="t('formulas.compare.count', { count: diff.removed.length })">
                <p v-if="!diff.removed.length" class="text-label text-ink-3">{{ t('formulas.compare.none') }}</p>
                <ul v-else class="flex flex-col divide-y divide-line">
                    <li v-for="line in diff.removed" :key="line.material_id" class="flex flex-wrap items-center gap-2 py-2" data-diff="removed">
                        <Badge tone="danger" icon="ph:minus">{{ t('formulas.compare.removed_badge') }}</Badge>
                        <span class="font-semibold text-ink">{{ line.material }}</span>
                        <span v-if="line.is_qs" class="text-label text-ink-3">{{ t('formulas.qs_short') }}</span>
                        <span class="ms-auto"><Num :value="line.percent_ww" /> %</span>
                    </li>
                </ul>
            </Card>

            <Card :title="t('formulas.compare.changed')" :description="t('formulas.compare.count', { count: diff.changed.length })">
                <p v-if="!diff.changed.length" class="text-label text-ink-3">{{ t('formulas.compare.none') }}</p>
                <ul v-else class="flex flex-col divide-y divide-line">
                    <li v-for="line in diff.changed" :key="line.material_id" class="flex flex-col gap-1 py-2" data-diff="changed">
                        <span class="font-semibold text-ink">{{ line.material }}</span>
                        <span v-for="(change, field) in line.changes" :key="field" class="flex flex-wrap items-baseline gap-2 text-label">
                            <span class="min-w-24 text-ink-3">{{ t(`formulas.compare.line_fields.${field}`) }}</span>
                            <span class="text-ink-3 line-through"><Num v-if="field === 'percent_ww'" :value="change.from" /><template v-else>{{ changeValue(field, change.from) }}</template></span>
                            <span aria-hidden="true">→</span>
                            <span class="font-semibold text-ink"><Num v-if="field === 'percent_ww'" :value="change.to" /><template v-else>{{ changeValue(field, change.to) }}</template></span>
                        </span>
                    </li>
                </ul>
            </Card>

            <Alert tone="info">{{ t('formulas.compare.sources_note') }}</Alert>
        </div>
    </div>
</template>
