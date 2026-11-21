<script setup>
/**
 * The one scaffold of every calculator page: header with a way back to the index, an inputs card (the default slot)
 * and a results card. The results card shows, from the calculator's outcome (lib/calculators/common.js):
 *   nothing yet  a hint, until something is typed
 *   missing      the engine's own list of what is lacking, each with its reason in words (never a zero)
 *   ready        every result as an <ExplainableNum> (click for the engine's calculation tree), grouped, with PASS/FAIL/UNKNOWN
 *                for a check, and the engine's warnings underneath
 * Titles, descriptions and result labels are lang keys from the calculator's id: calculators.items.<id>.*,
 * calculators.<id>.results.<key> (and .groups.<group> for a heading).
 *
 *   <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
 *       …the inputs…
 *       (persistent warnings go in the #notes slot)
 *   </CalculatorLayout>
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Alert, Badge, Card, EmptyState, PageHeader } from '@kit';
import { path } from '../../lib/routes';
import { useI18n } from '../../composables/useI18n';
import { provideCalculatorLookups } from '../../composables/useCalculatorLookups';
import { useProperties } from '../../composables/useProperties';
import ExplainableNum from '../explain/ExplainableNum.vue';
import StatusBadge from '../StatusBadge.vue';

const props = defineProps({
    /** The calculator's key in config/calculators.php (its address and its title). */
    calculator: { type: String, required: true },
    outcome: { type: Object, required: true },
    entities: { type: Object, default: () => ({}) },
    sources: { type: Object, default: () => ({}) },
    /** The lookups every input of the page shares (see useCalculatorLookups). */
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

provideCalculatorLookups({ urls: props.urls, confidenceOptions: props.confidenceOptions, sourceKindOptions: props.sourceKindOptions });

const { t, has } = useI18n();
const { label: propertyLabel } = useProperties();
const scope = computed(() => props.calculator);

const resultLabel = (result) => t(`calculators.${scope.value}.results.${result.key}`, result.params);
const unitLabel = (unit) => (unit && has(`calculators.units.${unit}`) ? t(`calculators.units.${unit}`) : unit);

/** Result rows with a heading where the group changes. */
const rows = computed(() => {
    let previous;

    return props.outcome.results.map((result) => {
        const heading = result.group !== previous && result.group ? t(`calculators.${scope.value}.groups.${result.group}`) : null;
        previous = result.group;

        return { ...result, heading };
    });
});

const subjectOf = (gap) => {
    const [head, ...detail] = gap.property.split(':');
    const known = has(`calculators.subjects.${head}`) ? t(`calculators.subjects.${head}`) : has(`properties.${head}`) ? propertyLabel(head) : head;

    return [gap.materialId, detail.length ? `${known} (${detail.join(':')})` : known].filter(Boolean).join(' · ');
};

/** The reason in words: the engine's key (engine.<key>), or a scoped bare one (engine.<scope>.<head>), or the engine's own text. */
function reasonOf(gap) {
    const head = gap.reason.split(':')[0].trim();
    const candidates = [`engine.${head}`, gap.scope ? `engine.${gap.scope}.${head}` : null].filter(Boolean);
    const found = candidates.find((key) => has(key));

    return found ? t(found) : t('engine.unknown_reason', { reason: gap.reason });
}

function warningOf(warning) {
    if (!has(`engine.${warning.key}`)) {
        return warning.raw;
    }

    const [material, property, confidence] = warning.details;

    return t(`engine.${warning.key}`, {
        material: material ?? '',
        property: property ?? '',
        confidence: confidence && has(`enums.confidence.${confidence}`) ? t(`enums.confidence.${confidence}`) : (confidence ?? ''),
        detail: warning.details.join(', '),
    });
}
</script>

<template>
    <Head :title="t(`calculators.items.${calculator}.title`)" />
    <div class="kit-page">
        <PageHeader
            :title="t(`calculators.items.${calculator}.title`)"
            :description="t(`calculators.items.${calculator}.description`)"
            :back="path('calculators.index')"
            :back-label="t('calculators.title')"
        />

        <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:items-start">
            <Card :title="t('calculators.inputs')" data-testid="inputs-card">
                <div class="flex flex-col gap-5">
                    <slot />
                </div>
            </Card>

            <div class="flex flex-col gap-4">
                <slot name="notes" />

                <Card :title="t('calculators.results')" data-testid="results-card" :data-state="outcome.state">
                    <EmptyState v-if="outcome.state === 'empty'" variant="dashed" icon="ph:calculator" :title="t('calculators.empty.title')" :description="t('calculators.empty.description')" />

                    <Alert v-else-if="outcome.state === 'missing'" tone="warning" icon="ph:warning" :title="t('calculators.missing.title')" data-testid="missing">
                        <p class="mb-2">{{ t('calculators.missing.description') }}</p>
                        <ul class="flex flex-col gap-1">
                            <li v-for="(gap, index) in outcome.missing" :key="index" data-testid="missing-item">
                                <span class="font-semibold">{{ subjectOf(gap) }}</span>: {{ reasonOf(gap) }}
                            </li>
                        </ul>
                    </Alert>

                    <ul v-else class="flex flex-col" data-testid="results">
                        <template v-for="result in rows" :key="result.key">
                            <li v-if="result.heading" class="mt-3 border-b border-line pb-1 text-label font-semibold text-ink-3 first:mt-0" role="presentation">{{ result.heading }}</li>
                            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-line py-2 last:border-b-0" :data-result="result.key">
                                <span :class="result.primary ? 'font-semibold text-ink' : 'text-ink-2'">
                                    {{ resultLabel(result) }}
                                    <Badge v-if="result.indicator" tone="warning" size="sm">{{ t('calculators.indicator') }}</Badge>
                                </span>
                                <span v-if="result.kind === 'status'" class="flex flex-col items-end gap-1">
                                    <StatusBadge kind="check_status" :status="result.status" />
                                    <span v-for="(gap, index) in result.missing" :key="index" class="text-label text-ink-3">{{ subjectOf(gap) }}: {{ reasonOf(gap) }}</span>
                                </span>
                                <span v-else :class="result.primary ? 'type-metric text-ink' : 'text-ink'">
                                    <ExplainableNum :traced="result.traced" :decimals="result.decimals" :label="resultLabel(result)" :entities="entities" :sources="sources" />
                                    <span v-if="unitLabel(result.traced.unit)" class="text-label font-normal text-ink-3">{{ unitLabel(result.traced.unit) }}</span>
                                </span>
                            </li>
                        </template>
                    </ul>

                    <ul v-if="outcome.warnings.length" class="mt-3 flex list-disc flex-col gap-1 ps-5 text-label text-ink-3" data-testid="warnings">
                        <li v-for="(warning, index) in outcome.warnings" :key="index">{{ warningOf(warning) }}</li>
                    </ul>
                </Card>

                <slot name="after-results" />
            </div>
        </div>
    </div>
</template>
