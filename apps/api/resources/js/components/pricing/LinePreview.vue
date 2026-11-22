<script setup>
/**
 * What the engine says about one price list line: the price, the unit cost, the margin (amount, % of price, % of cost), a
 * warning when the price is below cost, or exactly what is missing. Used by the line modal (before saving) and by the table
 * (after). The price opens the engine's calculation tree; nothing here is computed in the browser.
 *
 *   <LinePreview :engine="line.engine" />
 */
import { computed } from 'vue';
import { Alert, Badge } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { evaluateLine } from '../../lib/priceList';
import EngineMissing from '../explain/EngineMissing.vue';
import EngineWarnings from '../explain/EngineWarnings.vue';
import ExplainableNum from '../explain/ExplainableNum.vue';
import Num from '../Num.vue';

const props = defineProps({
    /** The engine input of the line: { unitCost?, spec? }. */
    engine: { type: Object, required: true },
    /** sourceId -> { title }: what each source in the explain tree is called (see priceSources in lib/priceList.js). */
    sources: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const line = computed(() => evaluateLine(props.engine));
const warnings = computed(() => (line.value.warnings ?? []).map((warning) => {
    const [key, ...details] = warning.split(':');

    return { key, details, raw: warning };
}));
</script>

<template>
    <div class="flex flex-col gap-3" data-testid="line-preview" :data-state="line.ok ? (line.belowCost ? 'below_cost' : 'ok') : 'missing'">
        <template v-if="line.ok">
            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-line bg-surface-2 p-3">
                    <dt class="text-label text-ink-3">{{ t('price_lists.preview.price') }}</dt>
                    <dd class="type-metric text-ink" data-testid="preview-price"><ExplainableNum :traced="line.priceTraced" :value="line.price" :decimals="2" :label="t('price_lists.preview.price')" :sources="sources" /> <span class="text-label font-normal text-ink-3">{{ t('price_lists.unit_egp') }}</span></dd>
                </div>
                <div class="rounded-lg border border-line bg-surface-2 p-3">
                    <dt class="text-label text-ink-3">{{ t('price_lists.preview.cost') }}</dt>
                    <dd class="type-metric text-ink" data-testid="preview-cost"><Num :value="line.cost" :decimals="2" /> <span class="text-label font-normal text-ink-3">{{ t('price_lists.unit_egp') }}</span></dd>
                </div>
                <div class="rounded-lg border border-line bg-surface-2 p-3">
                    <dt class="text-label text-ink-3">{{ t('price_lists.preview.margin') }}</dt>
                    <dd class="type-metric" :class="line.belowCost ? 'text-danger-ink' : 'text-ink'" data-testid="preview-margin"><Num :value="line.margin" :decimals="2" /> <span class="text-label font-normal text-ink-3">{{ t('price_lists.unit_egp') }}</span></dd>
                    <dd class="mt-1 flex flex-wrap gap-x-3 text-label text-ink-3">
                        <span>{{ t('price_lists.preview.on_price') }} <bdi dir="ltr"><Num :value="line.marginOnPricePct" :decimals="1" />%</bdi></span>
                        <span>{{ t('price_lists.preview.on_cost') }} <bdi dir="ltr"><Num :value="line.markupOnCostPct" :decimals="1" />%</bdi></span>
                    </dd>
                </div>
            </dl>
            <Alert v-if="line.belowCost" tone="danger" icon="ph:warning-octagon" :title="t('price_lists.preview.below_cost_title')" data-testid="below-cost">{{ t('price_lists.preview.below_cost') }}</Alert>
            <EngineWarnings v-if="warnings.length" :warnings="warnings" />
        </template>
        <EngineMissing v-else :missing="line.missing" />
    </div>
</template>
