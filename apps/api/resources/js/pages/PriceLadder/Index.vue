<script setup>
/**
 * The price ladder of a product (S-13): from its unit cost up to the shelf, and from a shelf price back down to the most it may cost, with what a
 * move of the dollar does to both. Every figure is the engine's (the one price waterfall, the product's tax from its dated regimes, the cost report's
 * USD-priced share); the margins are what-ifs typed here and a field left empty is listed as missing, never as 0. Any figure opens its calculation tree.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, EmptyState, FormField, Input, PageHeader } from '@kit';
import DecimalField from '../../components/form/DecimalField.vue';
import EntitySelect from '../../components/form/EntitySelect.vue';
import EngineMissing from '../../components/explain/EngineMissing.vue';
import ExplainableNum from '../../components/explain/ExplainableNum.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import Num from '../../components/Num.vue';
import LadderRatesFields from '../../components/pricing/LadderRatesFields.vue';
import UnitCostModal from '../../components/pricing/UnitCostModal.vue';
import { useI18n } from '../../composables/useI18n';
import { postJson } from '../../lib/http';
import { blankRates, ratesPayload, splitList } from '../../lib/ladder';
import { path } from '../../lib/routes';

const props = defineProps({
    product: { type: Object, default: null },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
    maxFxChanges: { type: Number, required: true },
});

const { t } = useI18n();
const costOpen = ref(false);
const rates = reactive(blankRates());
const inputs = reactive({ fill_kg: '', shelf_price: '', non_formula_cost_per_unit: '', fx_changes: '10' });
const state = reactive({ busy: false, errors: {}, failed: false, result: null });

const productLabel = computed(() => (props.product ? `${props.product.code} · ${props.product.name}` : ''));
const costRow = computed(() => (props.product ? { formula_id: props.product.id, code: props.product.code, name: props.product.name, unit_cost: props.product.unit_cost } : null));

const pickProduct = (option) => router.get(path('price-ladder.index'), { formula: option.value }, { preserveState: false });

async function calculate() {
    state.busy = true;
    state.failed = false;
    state.errors = {};

    try {
        const outcome = await postJson(props.urls.calculate, {
            formula_id: props.product.id,
            ...ratesPayload(rates),
            fill_kg: inputs.fill_kg,
            shelf_price: inputs.shelf_price,
            non_formula_cost_per_unit: inputs.non_formula_cost_per_unit,
            fx_changes_pct: splitList(inputs.fx_changes),
        });

        if (outcome.ok) {
            state.result = outcome.data;
        } else {
            state.errors = outcome.errors;
        }
    } catch {
        state.failed = true;
    } finally {
        state.busy = false;
    }
}

const stepLabel = (step) => step.label;
</script>

<template>
    <Head :title="t('price_ladder.title')" />
    <div class="kit-page" data-testid="price-ladder">
        <PageHeader :title="t('price_ladder.title')" :description="t('price_ladder.description')" />

        <div class="flex flex-col gap-4">
            <Card :title="t('price_ladder.product.title')">
                <div class="flex flex-col gap-4">
                    <EntitySelect :model-value="product?.id ?? null" label-key="price_ladder.product.pick" :search-url="urls.products" :selected-label="productLabel" @select="pickProduct" />

                    <template v-if="product">
                        <dl class="grid gap-4 sm:grid-cols-3" data-testid="ladder-product">
                            <div class="flex flex-col gap-1">
                                <dt class="text-label text-ink-3">{{ t('price_ladder.product.unit_cost') }}</dt>
                                <dd v-if="product.unit_cost" class="flex flex-wrap items-center gap-2 text-ink" data-testid="ladder-unit-cost">
                                    <span class="type-metric"><Num :value="product.unit_cost.value" :decimals="2" /></span>
                                    <span class="text-label text-ink-3">{{ t('price_ladder.units.egp_per_unit') }}</span>
                                    <ConfidenceBadge :confidence="product.unit_cost.confidence" />
                                    <span class="text-label text-ink-2">{{ product.unit_cost.source_title }}</span>
                                </dd>
                                <dd v-else><Num :value="null" reason-key="price_ladder.product.no_unit_cost" /></dd>
                                <Button variant="link" size="sm" icon="ph:pencil-simple" class="self-start" data-testid="ladder-set-cost" @click="costOpen = true">{{ t('price_lists.cost.title') }}</Button>
                            </div>
                            <div class="flex flex-col gap-1">
                                <dt class="text-label text-ink-3">{{ t('price_ladder.product.tax') }}</dt>
                                <dd class="text-ink" data-testid="ladder-tax">
                                    <Badge v-if="product.tax.set" tone="info" dot>{{ product.tax.label }}</Badge>
                                    <Badge v-else tone="warning" dot>{{ t('price_ladder.tax_not_set') }}</Badge>
                                </dd>
                            </div>
                            <div class="flex flex-col gap-1">
                                <dt class="text-label text-ink-3">{{ t('price_ladder.product.usd_rate') }}</dt>
                                <dd v-if="product.usd_rate" class="flex flex-wrap items-center gap-2 text-ink" data-testid="ladder-usd-rate">
                                    <span class="type-metric"><Num :value="product.usd_rate.rate" :decimals="2" /></span>
                                    <span class="text-label text-ink-3">{{ t('price_ladder.units.egp_per_usd') }}</span>
                                    <ConfidenceBadge :confidence="product.usd_rate.confidence" />
                                    <span class="text-label text-ink-2">{{ product.usd_rate.source }} · <bdi dir="ltr">{{ product.usd_rate.date }}</bdi></span>
                                </dd>
                                <dd v-else><Num :value="null" reason-key="price_ladder.product.no_usd_rate" /></dd>
                            </div>
                        </dl>
                    </template>
                    <EmptyState v-else icon="ph:stairs" :title="t('price_ladder.empty_title')" :description="t('price_ladder.empty_description')" />
                </div>
            </Card>

            <template v-if="product">
                <Card :title="t('price_ladder.inputs.title')" :description="t('price_ladder.inputs.description')" data-testid="ladder-inputs">
                    <div class="flex flex-col gap-5">
                        <LadderRatesFields v-model="rates" :errors="state.errors" with-leakage />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <DecimalField v-model="inputs.fill_kg" label-key="price_ladder.inputs.fill_kg" hint-key="price_ladder.inputs.fill_kg_hint" :suffix="t('price_ladder.units.kg')" :error="state.errors.fill_kg" />
                            <DecimalField v-model="inputs.shelf_price" label-key="price_ladder.inputs.shelf_price" hint-key="price_ladder.inputs.shelf_price_hint" :suffix="t('price_ladder.units.egp_per_unit')" :error="state.errors.shelf_price" />
                            <DecimalField v-model="inputs.non_formula_cost_per_unit" label-key="price_ladder.inputs.non_formula_cost" hint-key="price_ladder.inputs.non_formula_cost_hint" :suffix="t('price_ladder.units.egp_per_unit')" :error="state.errors.non_formula_cost_per_unit" />
                            <FormField :label="t('price_ladder.inputs.fx_changes')" :hint="t('price_ladder.inputs.fx_changes_hint', { max: maxFxChanges })" :error="state.errors.fx_changes_pct">
                                <Input v-model="inputs.fx_changes" ltr suffix="%" data-testid="fx-changes" />
                            </FormField>
                        </div>
                        <Alert v-if="state.failed" tone="danger" icon="ph:warning-circle">{{ t('price_ladder.failed') }}</Alert>
                        <div>
                            <Button icon="ph:calculator" :loading="state.busy" data-testid="ladder-calculate" @click="calculate">{{ t('price_ladder.calculate') }}</Button>
                        </div>
                    </div>
                </Card>

                <template v-if="state.result">
                    <Card v-if="state.result.forward" :title="t('price_ladder.forward.title')" :description="t('price_ladder.forward.description')" data-testid="ladder-forward">
                        <EngineMissing v-if="!state.result.forward.ok" :missing="state.result.forward.missing" />
                        <div v-else class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-baseline gap-3">
                                <span class="type-metric text-ink" data-testid="ladder-consumer-price">
                                    <ExplainableNum :traced="state.result.forward.consumer_price" :decimals="2" :label="t('price_ladder.forward.consumer_price')" />
                                </span>
                                <span class="text-label text-ink-3">{{ t('price_ladder.units.egp_per_unit') }}</span>
                                <Badge :tone="state.result.forward.tax.state === 'applied' ? 'info' : 'neutral'" dot data-testid="ladder-tax-state">
                                    {{ state.result.forward.tax.state === 'applied' ? t('price_ladder.forward.tax_applied', { rate: state.result.forward.tax.ratePct }) : t('price_ladder.forward.tax_disabled') }}
                                </Badge>
                            </div>
                            <ol class="flex flex-col divide-y divide-line rounded-lg border border-line" data-testid="ladder-forward-steps">
                                <li v-for="step in state.result.forward.steps" :key="step.key" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2" :data-step="step.key">
                                    <span class="text-ink-2">{{ stepLabel(step) }}</span>
                                    <span class="text-ink"><ExplainableNum :traced="step.traced" :decimals="2" :label="stepLabel(step)" /></span>
                                </li>
                            </ol>
                        </div>
                    </Card>

                    <Card v-if="state.result.reverse" :title="t('price_ladder.reverse.title')" :description="t('price_ladder.reverse.description')" data-testid="ladder-reverse">
                        <EngineMissing v-if="!state.result.reverse.ok" :missing="state.result.reverse.missing" />
                        <div v-else class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-baseline gap-3">
                                <span class="type-metric text-ink" data-testid="ladder-max-unit-cost"><Num :value="state.result.reverse.max_unit_cost" :decimals="2" /></span>
                                <span class="text-label text-ink-3">{{ t('price_ladder.reverse.max_unit_cost') }}</span>
                                <Badge :tone="state.result.reverse.tax.state === 'applied' ? 'info' : 'neutral'" dot>
                                    {{ state.result.reverse.tax.state === 'applied' ? t('price_ladder.forward.tax_applied', { rate: state.result.reverse.tax.rate_pct }) : t('price_ladder.forward.tax_disabled') }}
                                </Badge>
                            </div>
                            <ol class="flex flex-col divide-y divide-line rounded-lg border border-line" data-testid="ladder-reverse-steps">
                                <li v-for="step in state.result.reverse.steps" :key="step.key" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                                    <span class="text-ink-2">{{ step.label }}</span>
                                    <span class="text-ink"><Num :value="step.value" :decimals="2" /></span>
                                </li>
                            </ol>
                            <p v-if="state.result.reverse.max_formula_cost_per_kg === null" class="text-label text-ink-3">{{ t('price_ladder.reverse.no_formula_cost') }}</p>
                        </div>
                    </Card>

                    <Card v-if="state.result.fx" :title="t('price_ladder.fx.title')" :description="t('price_ladder.fx.description')" data-testid="ladder-fx">
                        <div class="flex flex-col gap-4">
                            <EngineMissing v-if="!state.result.fx.exposure.ok" :missing="state.result.fx.exposure.missing" />
                            <dl v-else class="grid gap-4 sm:grid-cols-3" data-testid="fx-exposure">
                                <div class="flex flex-col gap-1">
                                    <dt class="text-label text-ink-3">{{ t('price_ladder.fx.material_cost') }}</dt>
                                    <dd class="text-ink"><Num :value="state.result.fx.exposure.materialCostPerKg" :decimals="2" /> {{ t('price_ladder.units.egp_per_kg') }}</dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-label text-ink-3">{{ t('price_ladder.fx.usd_linked') }}</dt>
                                    <dd class="text-ink"><Num :value="state.result.fx.exposure.usdLinkedPerKg" :decimals="2" /> {{ t('price_ladder.units.egp_per_kg') }}</dd>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <dt class="text-label text-ink-3">{{ t('price_ladder.fx.usd_share') }}</dt>
                                    <dd class="text-ink" data-testid="fx-share"><Num :value="state.result.fx.exposure.usdSharePct" :decimals="1" /> %</dd>
                                </div>
                            </dl>
                            <Alert tone="info" icon="ph:info">{{ t('price_ladder.fx.scope_note') }}</Alert>

                            <template v-if="state.result.fx.sensitivity">
                                <EngineMissing v-if="!state.result.fx.sensitivity.ok" :missing="state.result.fx.sensitivity.missing" />
                                <div v-else class="overflow-x-auto">
                                    <table class="w-full min-w-[34rem] text-start" data-testid="fx-table">
                                        <thead>
                                            <tr class="text-label text-ink-3">
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('price_ladder.fx.columns.change') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('price_ladder.fx.columns.rate') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('price_ladder.fx.columns.unit_cost') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('price_ladder.fx.columns.consumer_price') }}</th>
                                                <th class="py-2 text-start font-medium">{{ t('price_ladder.fx.columns.delta') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-line">
                                            <tr v-for="row in state.result.fx.sensitivity.rows" :key="row.changePct" :data-change="row.changePct">
                                                <td class="py-2 pe-3 text-ink"><bdi dir="ltr" class="tnum">{{ row.changePct }}%</bdi></td>
                                                <td class="py-2 pe-3"><Num :value="row.usdRateAfter" :decimals="2" /></td>
                                                <td class="py-2 pe-3"><Num :value="row.unitCost === '' ? null : row.unitCost" :decimals="2" /></td>
                                                <td class="py-2 pe-3" data-testid="fx-consumer"><Num :value="row.consumerPrice" :decimals="2" /></td>
                                                <td class="py-2" data-testid="fx-delta">
                                                    <template v-if="row.consumerPrice !== null"><Num :value="row.deltaConsumerPrice" :decimals="2" /> (<Num :value="row.deltaConsumerPct" :decimals="2" />%)</template>
                                                    <span v-else class="text-warning-ink">{{ t('price_ladder.fx.row_missing') }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </div>
                    </Card>
                </template>
            </template>
        </div>

        <UnitCostModal v-model:open="costOpen" :row="costRow" :confidence-options="confidenceOptions" :source-kind-options="sourceKindOptions" :urls="{ sources: urls.sources, sourceStore: urls.sourceStore }" />
    </div>
</template>
