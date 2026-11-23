<script setup>
/**
 * Home (S-15): the figures a person decides on, every one the engine's answer over the app's own records. A figure that cannot be worked out is "—" with
 * the reason beside it, never 0: stale prices need the owner's threshold, compliance needs compositions, the margin effect needs a published price.
 * The figures arrive after the page opens (a deferred prop), so the page never waits for the engine.
 */
import { computed } from 'vue';
import { Deferred, Head, usePage } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, EmptyState, PageHeader, Skeleton, StatCard } from '@kit';
import ConfidenceBadge from '../components/materials/ConfidenceBadge.vue';
import Num from '../components/Num.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { useEngineText } from '../composables/useEngineText';
import { useI18n } from '../composables/useI18n';
import { path } from '../lib/routes';

const props = defineProps({
    kpis: { type: Object, default: undefined },
});

const { t } = useI18n();
const fieldAudience = computed(() => usePage().props.fieldAudience);
const { reasonOf } = useEngineText();

/** The reason a figure is missing: the first gap, worded by the server or by the engine's lang group. */
const reasonFor = (gaps) => (gaps?.length ? (gaps[0].text ?? reasonOf(gaps[0])) : null);
const reasonKeyOf = (gap) => `engine.${gap.reason.split(':')[0]}`;
const k = computed(() => props.kpis ?? null);

const cards = computed(() => {
    if (!k.value) {
        return [];
    }
    const { stale, quality, compliance, approvals, impact } = k.value;

    return [
        // A role that does not see costs is sent neither the stale-price nor the price-move figures (U-07): no card is drawn for them.
        ...(stale ? [{
            id: 'stale',
            label: t('dashboard.kpi.stale'),
            value: stale.value,
            reason: reasonFor(stale.missing),
            caption: stale.checked === null ? null : t('dashboard.kpi.stale_caption', { checked: stale.checked }),
            icon: 'ph:clock-countdown',
            alert: stale.value !== null && stale.value !== '0',
        }] : []),
        {
            id: 'quality',
            label: t('dashboard.kpi.quality'),
            value: quality.value,
            reason: t('dashboard.kpi.no_formulas'),
            caption: t('dashboard.kpi.quality_caption', { checked: quality.checked }),
            icon: 'ph:warning-diamond',
            alert: quality.value !== null && quality.value !== '0',
        },
        {
            id: 'compliance',
            label: t('dashboard.kpi.compliance'),
            value: compliance.value,
            reason: t('dashboard.kpi.no_compliance'),
            caption: t('dashboard.kpi.compliance_caption', { fail: compliance.fail, unknown: compliance.unknown, checked: compliance.checked }),
            icon: 'ph:seal-warning',
            alert: compliance.fail !== '0',
        },
        {
            id: 'approvals',
            label: t('dashboard.kpi.approvals'),
            value: approvals.value,
            reason: null,
            caption: approvals.costs_to_approve === undefined
                ? t('dashboard.kpi.approvals_caption_no_cost', { lab: approvals.versions_in_lab, orders: approvals.orders_to_approve, deviations: approvals.deviations_pending })
                : t('dashboard.kpi.approvals_caption', { lab: approvals.versions_in_lab, orders: approvals.orders_to_approve, deviations: approvals.deviations_pending, costs: approvals.costs_to_approve }),
            icon: 'ph:hourglass-medium',
            alert: false,
        },
        ...(impact ? [{
            id: 'impact',
            label: t('dashboard.kpi.impact'),
            value: impact.value === null ? null : `${impact.value.startsWith('-') ? '' : '+'}${new Intl.NumberFormat('en', { maximumFractionDigits: 2 }).format(impact.value)}%`,
            reason: t('dashboard.kpi.no_impact'),
            caption: impact.largest ? t('dashboard.kpi.impact_caption', { code: impact.largest.code }) : null,
            icon: 'ph:trend-up',
            alert: false,
        }] : []),
    ];
});

const approvalKeys = computed(() => ['versions_in_lab', 'orders_to_approve', 'deviations_pending', 'costs_to_approve'].filter((key) => k.value?.approvals[key] !== undefined));
const gapLine = (gap) => [[gap.materialId, gap.subject].filter(Boolean).join(' · ') || gap.property, gap.text ?? reasonOf(gap)].join(': ');
const missingLines = (row) => row.missing.slice(0, 2).map(gapLine);
</script>

<template>
    <Head :title="t('dashboard.title')" />
    <div class="kit-page" data-testid="dashboard">
        <PageHeader :title="t('dashboard.title')" :description="t('dashboard.description')" />

        <Deferred data="kpis">
            <template #fallback>
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5" :aria-label="t('dashboard.title')" data-testid="kpis-loading">
                    <Skeleton v-for="n in 5" :key="n" class="h-32 rounded-lg" />
                </section>
            </template>

            <template v-if="k">
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5" :aria-label="t('dashboard.title')" data-testid="kpis">
                    <StatCard
                        v-for="card in cards"
                        :key="card.id"
                        :label="card.label"
                        :value="card.value"
                        :reason="card.reason ?? undefined"
                        :caption="card.caption ?? undefined"
                        :icon="card.icon"
                        :alert="card.alert"
                        :data-kpi="card.id"
                    />
                </section>

                <EmptyState v-if="k.formula_count === 0" class="mt-6" icon="ph:flask" :title="t('dashboard.empty_title')" :description="t('dashboard.empty_description')" />

                <div class="mt-6 grid gap-4 lg:grid-cols-2">
                    <Card v-if="k.stale" :title="t('dashboard.stale.title')" :description="t('dashboard.stale.description', { date: k.as_of })" data-testid="card-stale">
                        <Alert v-if="k.stale.missing.length" tone="warning" icon="ph:warning" data-testid="stale-missing">{{ reasonFor(k.stale.missing) }}</Alert>
                        <template v-else>
                            <ul v-if="k.stale.items.length" class="flex flex-col divide-y divide-line">
                                <li v-for="row in k.stale.items" :key="row.material" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <a :href="row.href" class="font-semibold text-primary-ink underline underline-offset-2">{{ row.material }}</a>
                                    <span class="text-label text-ink-3"><bdi dir="ltr" class="tnum">{{ row.quoted_on }}</bdi> · {{ t('dashboard.stale.age', { days: row.age_days }) }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-ink-2" data-testid="stale-none">{{ t('dashboard.stale.none') }}</p>
                            <p v-if="k.stale.unpriced.length" class="mt-3 text-label text-ink-3" data-testid="stale-unpriced">{{ t('dashboard.stale.unpriced') }} <bdi v-for="name in k.stale.unpriced" :key="name" class="me-2">{{ name }}</bdi></p>
                        </template>
                    </Card>

                    <Card :title="t('dashboard.quality.title')" :description="t('dashboard.quality.description')" data-testid="card-quality">
                        <ul v-if="k.quality.items.length" class="flex flex-col divide-y divide-line">
                            <li v-for="row in k.quality.items" :key="row.formula.id" class="flex flex-col gap-1 py-2" :data-formula="row.formula.code">
                                <a :href="row.formula.href" class="font-semibold text-primary-ink underline underline-offset-2">{{ row.formula.code }} · {{ row.formula.name }}</a>
                                <span v-if="row.missing.length" class="text-label text-ink-2">
                                    <Badge tone="warning" size="sm" dot>{{ t('dashboard.quality.missing', { count: row.missing.length }) }}</Badge>
                                    <bdi v-for="(line, index) in missingLines(row)" :key="index" class="me-2">{{ line }}</bdi>
                                </span>
                                <span v-if="row.low.length" class="flex flex-wrap items-center gap-2 text-label text-ink-2">
                                    <ConfidenceBadge :confidence="row.low[0].confidence" />
                                    {{ t('dashboard.quality.low', { count: row.low.length }) }} <bdi v-for="item in row.low" :key="item.material" class="me-2">{{ item.material }}</bdi>
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-ink-2" data-testid="quality-none">{{ k.quality.value === null ? t('dashboard.kpi.no_formulas') : t('dashboard.quality.none') }}</p>
                    </Card>

                    <Card :title="t('dashboard.compliance.title')" :description="t('dashboard.compliance.description')" data-testid="card-compliance">
                        <ul v-if="k.compliance.items.length" class="flex flex-col divide-y divide-line">
                            <li v-for="row in k.compliance.items" :key="row.formula.id" class="flex flex-wrap items-center justify-between gap-2 py-2" :data-formula="row.formula.code">
                                <a :href="row.formula.href" class="font-semibold text-primary-ink underline underline-offset-2">{{ row.formula.code }} · {{ row.formula.name }}</a>
                                <span class="flex items-center gap-2">
                                    <span class="text-label text-ink-3">{{ t('dashboard.compliance.counts', { failed: row.failed, unknown: row.unknown }) }}</span>
                                    <StatusBadge kind="check_status" :status="row.status" />
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-ink-2" data-testid="compliance-none">{{ k.compliance.value === null ? t('dashboard.kpi.no_compliance') : t('dashboard.compliance.none') }}</p>
                        <p v-if="k.compliance.not_applicable !== '0'" class="mt-3 text-label text-ink-3">{{ t('dashboard.compliance.not_applicable', { count: k.compliance.not_applicable }) }}</p>
                    </Card>

                    <Card :title="t('dashboard.approvals.title')" :description="t('dashboard.approvals.description')" data-testid="card-approvals">
                        <dl class="grid grid-cols-2 gap-3">
                            <div v-for="key in approvalKeys" :key="key" class="flex flex-col gap-1">
                                <dt class="text-label text-ink-3">{{ t(`dashboard.approvals.${key}`) }}</dt>
                                <dd class="type-metric text-ink" :data-approval="key"><Num :value="k.approvals[key]" :decimals="0" /></dd>
                            </div>
                        </dl>
                        <ul v-if="k.approvals.items.length" class="mt-3 flex flex-col divide-y divide-line">
                            <li v-for="row in k.approvals.items" :key="`${row.kind}${row.label}`" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <a :href="row.href" class="text-primary-ink underline underline-offset-2">{{ row.label }}</a>
                                <Badge tone="neutral" size="sm">{{ t(`dashboard.approvals.kind.${row.kind}`) }}</Badge>
                            </li>
                        </ul>
                    </Card>

                    <Card v-if="k.impact" :title="t('dashboard.impact.title')" :description="t('dashboard.impact.description', { threshold: k.impact.threshold_pct })" class="lg:col-span-2" data-testid="card-impact">
                        <ul v-if="k.impact.items.length" class="flex flex-col divide-y divide-line">
                            <li v-for="row in k.impact.items" :key="row.formula.id" class="grid gap-2 py-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1.2fr)] sm:items-center" :data-formula="row.formula.code">
                                <a :href="row.formula.href" class="font-semibold text-primary-ink underline underline-offset-2">{{ row.formula.code }} · {{ row.formula.name }}</a>
                                <span class="text-ink" data-testid="impact-cost">
                                    <template v-if="row.impact">
                                        <Num :value="row.impact.currentCostPerKg" :decimals="2" /> <span class="text-label text-ink-3">{{ t('dashboard.impact.was') }} <Num :value="row.impact.approvedCostPerKg" :decimals="2" /></span>
                                        (<Num :value="row.impact.deltaPct" :decimals="2" />%)
                                    </template>
                                    <Num v-else :value="null" :reason-key="row.impact_missing.length ? reasonKeyOf(row.impact_missing[0]) : 'common.no_data_yet'" />
                                </span>
                                <span class="text-ink" data-testid="impact-margin">
                                    <template v-if="row.margin">
                                        {{ t('dashboard.impact.margin_bound') }}
                                        <Num :value="row.margin.marginNowPct" :decimals="1" />% → <Num :value="row.margin.marginAtBoundPct" :decimals="1" />%
                                        (<Num :value="row.margin.deltaPoints" :decimals="1" />)
                                    </template>
                                    <Num v-else :value="null" :reason-key="row.margin_missing.length ? reasonKeyOf(row.margin_missing[0]) : 'common.no_data_yet'" />
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-ink-2" data-testid="impact-none">{{ t('dashboard.impact.none') }}</p>
                        <p class="mt-3 text-label text-ink-3">{{ t('dashboard.impact.bound_note') }}</p>
                    </Card>
                </div>

                <div v-if="fieldAudience?.sees_price && fieldAudience?.sees_cost" class="mt-6 flex flex-wrap gap-2 print:hidden">
                    <Button :href="path('tiers.index')" variant="secondary" icon="ph:stack" size="sm">{{ t('nav.tiers') }}</Button>
                    <Button :href="path('price-ladder.index')" variant="secondary" icon="ph:stairs" size="sm">{{ t('nav.price_ladder') }}</Button>
                </div>
            </template>
        </Deferred>
    </div>
</template>
